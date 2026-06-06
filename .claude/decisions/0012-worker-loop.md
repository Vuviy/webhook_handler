# 0012. Worker loop: consume API, status state machine, and interim failure behaviour

- **Status:** Accepted
- **Date:** 2026-06-06

## Context

T2.2 opens the *running* worker. T2.1 (ADR 0011) built the dispatch seam — a
`Handler` interface, `HandlerRegistry::get()`, a read-only `WebhookEvent`, and the two
typed exceptions — but nothing consumes the queue yet. The spec's worker flow is
`BLPOP webhooks:queue → status=processing → handler → status=processed`
(`webhook-handler.spec.md:60-62`, `queue-processing/SKILL.md:52-58`).

The hard part of T2.2 is **scope discipline**. The full loop in the skill already names
retry, the `webhooks:retry` zset, the DLQ and `attempts++`. But:

- **Retry/backoff math is T2.3.**
- **The DLQ list is T2.4.**
- **Graceful SIGTERM shutdown is T2.5.**

T2.2 must deliver a working happy path *and* a defined behaviour for failures, **without**
building any of that machinery — and leave seams so T2.3/T2.4/T2.5 slot in by addition,
not rewrite.

Constraints carried in from existing code:

- `Queue` owns the envelope: it RPUSHes `{event_id, provider, attempt}` to `webhooks:queue`
  and its docblock states consuming "belongs to the worker tasks (T2.x)"
  (`Queue.php:23-24`, `Queue.php:89-99`). It has `encodeJob()` but no decode/pop yet.
- `EventRepository` exposes only `insertReceived()`; there is **no read-by-id and no status
  update** (`EventRepository.php:42`, noted in ADR 0011 as T2.2's job).
- `WebhookEvent` is the worker's output contract: `(provider, eventId, eventType, payload,
  attempt)` (`WebhookEvent.php:33-40`). The worker is its single producer (`WebhookEvent.php:13-17`).
- `Handler::handle(): void` — normal return = success; failure via
  `TransientHandlerException` / `PermanentHandlerException`; **any other `Throwable` is to be
  treated as transient** (`Handler.php:31-44`, ADR 0011 Decision 2).
- `HandlerRegistry::get()` returns `null` for an unknown provider; the worker decides what
  that means (`HandlerRegistry.php:29-37`).
- Delivery is **at-least-once**: a job can be re-popped (`queue-processing/SKILL.md:26-28`,
  `webhook-handler.spec.md:94`).
- Entry-point pattern: `$config = require __DIR__ . '/../bootstrap.php';` then build deps and
  run (`bin/migrate.php:26-29`). PDO is `ERRMODE_EXCEPTION` (`Connection.php`). The
  front controller wraps the whole dispatch in one `try { … } catch (\Throwable)` backstop
  with `error_log((string) $e)` and never leaks detail (`public/index.php:54-63`) — the
  worker should reuse that discipline.
- docker-compose runs `php worker.php` at the app root, `restart: unless-stopped`
  (`docker-compose.yml:46`). So the file is `app/worker.php`, **not** in `bin/`.

Four genuine choices arise; the rest is mechanical.

## Options considered

### Decision 1 — the queue consume API on `Queue`

`Queue` owns `encodeJob()`, so it must own decode too; the question is the *shape* it returns.

#### Option A — `consume(int $timeout): ?array` returning the raw decoded array
- Pros: No new type; trivial.
- Cons: Hands back an untyped `array<string,mixed>` and re-leaks the envelope's field names
  (`event_id`, `provider`, `attempt`) to the worker — the exact thing the encode-side
  encapsulation exists to prevent. Every caller must re-validate keys. Inconsistent with the
  codebase's "parse once, hand a typed value to the next layer" shape (`VerificationResult`,
  `WebhookEvent`).

#### Option B — `consume(int $timeout): ?Job` returning a small readonly `Job` value object
A new `final readonly class Job { eventId, provider, attempt }` with accessors. `Queue` BLPOPs,
JSON-decodes inside a private `decodeJob()` (the mirror of `encodeJob()`), validates the three
fields, and returns a `Job`; returns `null` when BLPOP times out.
- Pros: Symmetric with `encodeJob()` — the envelope schema stays sealed inside `Queue` on both
  sides. The worker gets a typed value, never touches array keys. A malformed/legacy envelope is
  caught in one place (`Queue` throws `QueueException`), not scattered. Matches the established
  value-object idiom. Decode lives where encode lives.
- Cons: One more tiny type (`App\Queue\Job`). It overlaps `WebhookEvent` in two fields
  (`provider`, `attempt`) — but they are different things: `Job` is the *queue reference*
  (what to fetch), `WebhookEvent` is the *fetched, verified row* (what to process). Conflating
  them would drag DB columns into the queue layer.

#### Option C — pass a callback: `Queue::consume(callable $handler, int $timeout)`
- Pros: The loop lives inside `Queue`.
- Cons: Buries the worker's control flow (status transitions, the catch-all) inside the queue
  abstraction and makes T2.5's shutdown flag and T2.3's requeue awkward to thread through. Inverts
  ownership — the worker, not the queue, owns processing policy.

#### BLPOP timeout vs block-forever (sub-decision of Decision 1)
- **Block forever (`BLPOP … 0`)**: simplest, but the loop never regains control between jobs, so
  T2.5 cannot check a shutdown flag without a hard kill, and a `RedisException` only surfaces on the
  next push.
- **Finite timeout (e.g. `BLPOP … 5`)** returning `null` on timeout: the loop wakes periodically
  with no job, which is the natural place for T2.5 to test `$shouldStop` and for the loop to notice a
  dropped connection. Costs one cheap empty wakeup every N seconds.

### Decision 2 — `EventRepository` additions (read + status writes)

T2.2 needs (a) read the row to build `WebhookEvent`, and (b) move status. Minimal surface:

#### Option D — one generic `updateStatus(int $id, string $status, ?string $error)`
- Pros: One method.
- Cons: A stringly-typed status invites a typo'd enum value (silently breaks the dashboard), and a
  generic setter doesn't express the *guarded* transition the happy path needs. It also doesn't know
  to stamp `processed_at`.

#### Option E — intent-named methods, guarded by current status (optimistic claim)
- `findForProcessing(string $provider, string $eventId): ?array` — the single read.
- `markProcessing(int $id): bool` — `UPDATE … SET status='processing', updated_at=NOW()
  WHERE id=:id AND status='received'`; returns whether *this* call won the claim.
- `markProcessed(int $id): void` — `SET status='processed', processed_at=NOW()`.
- `markFailed(int $id, string $error): void` — `SET status='failed', last_error=:error` (interim;
  T2.3/T2.4 will replace the call sites, not necessarily this method).
- Pros: `markProcessing` being guarded (`AND status='received'`) gives **at-least-once safety for
  free**: if a job is re-popped after it already advanced, the `WHERE` matches 0 rows, `rowCount()===0`,
  and the worker skips it instead of re-running the handler. That is the worker-level dedupe the spec
  demands (`webhook-handler.spec.md:94`, FR-11) without a second lookup. Intent-named methods read like
  the state machine; `last_error` is never logged elsewhere (it can hold provider detail) — kept to the DB.
- Cons: Four methods instead of one. The guard means T2.3 (which legitimately re-runs from
  `received` after a scheduled retry) is already compatible — a retried job is back at `received`, so
  the claim succeeds again. Accepted.

#### Option F — unconditional status writes
- Pros: Simplest SQL.
- Cons: Throws away the cheap re-pop guard; a duplicate delivery would re-run the handler and only the
  handler's own idempotency would save us. Weaker, for no real saving.

### Decision 3 — interim failure behaviour (no retry, no DLQ yet)

This is the crux of "honest but minimal". On `TransientHandlerException`, `PermanentHandlerException`,
or any other `Throwable` from the handler, T2.2 has no retry queue and no DLQ to send the job to.

#### Option G — leave failures undefined / let the exception escape the loop
- Pros: Zero code.
- Cons: An escaping exception kills the worker process; `restart: unless-stopped` then hot-loops on a
  poison message (`webhook-handler.spec.md:92`). The DB row is stranded in `processing` forever, which
  the dashboard will misreport. Unacceptable even as a stopgap.

#### Option H — catch all three, mark `failed` + record `last_error`, do **not** requeue
The loop catches `TransientHandlerException`, `PermanentHandlerException` and the catch-all `Throwable`,
calls `markFailed($id, $message)`, logs one line, and moves to the next job. No retry, no DLQ list.
- Pros: The happy path and the failure path are both *defined and bounded*; the worker never crashes on a
  bad job and never strands a row in `processing`. The three `catch` blocks are the **exact seam** T2.3/T2.4
  need: T2.3 fills the transient branch with "schedule retry / mark received", T2.4 fills the exhausted and
  permanent branches with "push to `webhooks:dlq`". T2.2 ships the structure; later tasks fill the bodies.
  `markFailed` already writes `last_error`, satisfying the audit requirement now (FR-9, NFR-4).
- Cons: An interim `failed` is *semantically stronger than reality* — a transient fault that T2.3 would
  later retry is marked terminally `failed` in T2.2. This is acknowledged debt for one task: until T2.3
  lands, a transient failure is not retried. It is honest (logged, audited, visible) rather than silent.
  Documented in the worker docblock and here.

#### Option I — mark `failed` but distinguish transient (leave at `received`) vs permanent now
- Pros: Closer to final semantics.
- Cons: "Leave at `received`" without a retry scheduler means the row sits at `received` but is **not**
  back on the queue — an invisible stuck state worse than `failed`. Re-deriving requeue here is exactly the
  T2.3 machinery this task must not build. Rejected.

### Decision 4 — loop-level resilience (Redis/DB drops) without a hot crash-loop

The handler's failures are caught per Decision 3. Separately, *infrastructure* faults (Redis BLPOP throws
`RedisException`, DB `PDOException` during a status write) can hit the loop body around the dispatch.

#### Option J — let infra faults escape; rely on `restart: unless-stopped`
- Cons: A flapping Redis/MySQL makes the container crash-restart in a tight loop, spamming logs and never
  recovering gracefully; the in-flight job's row may be stuck `processing`.

#### Option K — wrap the loop body in a `try/catch (\Throwable)`, log, short sleep, continue
An **outer** `try/catch` around the per-iteration body: on any infra `Throwable` not already handled as a
handler failure, `error_log((string) $e)` (same discipline as `public/index.php:61`), `sleep` a small
fixed backoff (e.g. 1s) so a down dependency doesn't busy-spin, then continue the loop.
- Pros: The worker survives transient infra outages and recovers when the dependency returns, instead of
  crash-looping. Mirrors the front controller's single-backstop philosophy. The 1s sleep is the only "magic
  number" and is infra backoff, **not** the retry backoff of T2.3 (kept clearly separate).
- Cons: A genuinely fatal misconfiguration (wrong DSN) will log-and-sleep forever rather than exit loudly.
  Accepted for T2.2: the operator sees the repeated log line; a fail-fast-on-startup refinement can come later
  if wanted. Connections are built **once before** the loop (like `migrate.php`), so a startup outage still
  fails fast at boot before the loop begins.

## Decision

- **Decision 1: Option B with a finite BLPOP timeout.** Add to `Queue`:
  `consume(int $timeoutSeconds): ?Job`, returning a new `final readonly class App\Queue\Job`
  (`eventId`, `provider`, `attempt`) or `null` on timeout. Decode + envelope validation live in a private
  `decodeJob()` mirroring `encodeJob()`; a malformed envelope throws `QueueException` (add a
  `decodeFailed(string $raw...)`-style factory that names neither payload nor secret). The worker passes a
  small finite timeout so T2.5 has a natural wakeup to check shutdown and the loop can notice infra faults.
  Reject Option A (untyped, re-leaks the schema) and Option C (inverts processing ownership).

- **Decision 2: Option E** — intent-named, guarded `EventRepository` methods:
  `findForProcessing(provider, eventId): ?array`, `markProcessing(id): bool` (guarded
  `AND status='received'`), `markProcessed(id): void`, `markFailed(id, error): void`. The guard on
  `markProcessing` is the worker-level dedupe for re-popped jobs (FR-11, at-least-once) at no extra cost.
  Reject Option D (stringly-typed, unguarded) and Option F (drops the free dedupe).

- **Decision 3: Option H** — interim failure = **catch all three failure kinds, `markFailed` + record
  `last_error`, log one line, no requeue, no DLQ.** The three `catch` blocks are written now as the seam;
  their *bodies* are the interim "mark failed" and will be **replaced** (not wrapped) by T2.3 (transient →
  schedule retry / mark `received`) and T2.4 (exhausted/permanent → `webhooks:dlq` + `failed`). The known,
  documented debt: until T2.3, a transient failure is terminally marked `failed` rather than retried —
  honest and audited, not silent.

- **Decision 4: Option K** — connections built once before the loop (fail fast at boot, per `migrate.php`);
  the per-iteration body wrapped in an outer `try/catch (\Throwable)` that logs and sleeps a small fixed
  interval before continuing, so an infra outage degrades to retry-the-loop instead of a crash-loop. This 1s
  sleep is infra backoff and is explicitly **not** the T2.3 retry backoff.

### Worker happy-path status state machine (T2.2)

```
received --markProcessing (guarded)--> processing --handler ok--> processed
   ^                                        |
   |  (re-pop: guard matches 0 rows         |  handler throws Transient|Permanent|other
   |   -> skip, no re-run)                   v
                                          failed   (interim: marked here for ALL failures;
                                                    T2.3 reroutes transient, T2.4 adds DLQ)
```

### worker.php skeleton (structure only — no feature code)

```
$config = require __DIR__ . '/bootstrap.php';     // note: app root, not bin/
$queue    = Queue::fromConfig($config->redis());
$pdo      = Connection::fromConfig($config->database());
$events   = new EventRepository($pdo);
$registry = new HandlerRegistry();

while (true) {
    try {
        $job = $queue->consume(self::BLPOP_TIMEOUT);   // ?Job
        if ($job === null) { continue; }               // timed out -> (T2.5 will check shutdown)

        $row = $events->findForProcessing($job->provider(), $job->eventId());
        if ($row === null) { log "row vanished"; continue; }      // at-least-once: nothing to do

        if (!$events->markProcessing((int) $row['id'])) { continue; }  // re-pop / already advanced -> skip

        $handler = $registry->get($job->provider());
        if ($handler === null) {                       // unknown provider = permanent
            $events->markFailed((int) $row['id'], "no handler for provider {$job->provider()}");
            continue;
        }

        $event = new WebhookEvent($job->provider(), $job->eventId(), $row['event_type'], $row['payload'], $job->attempt());

        try {
            $handler->handle($event);
            $events->markProcessed((int) $row['id']);
        } catch (TransientHandlerException $e) {       // SEAM for T2.3 (schedule retry / mark received)
            $events->markFailed((int) $row['id'], $e->getMessage());
        } catch (PermanentHandlerException $e) {       // SEAM for T2.4 (-> webhooks:dlq + failed)
            $events->markFailed((int) $row['id'], $e->getMessage());
        } catch (\Throwable $e) {                      // catch-all = transient (ADR 0011); SEAM for T2.3
            $events->markFailed((int) $row['id'], $e->getMessage());
        }
    } catch (\Throwable $e) {                          // infra fault (Redis/DB) — Decision 4
        error_log((string) $e);
        sleep(self::INFRA_BACKOFF_SECONDS);            // NOT the T2.3 retry backoff
    }
}
```

## Consequences

- **Positive:**
  - Happy path works end-to-end (T0.4 enqueue → worker → `processed`), satisfying AC-2.
  - The three handler-failure `catch` blocks are the precise insertion points for T2.3 (retry) and
    T2.4 (DLQ): later tasks replace block bodies, not the loop shape or the interface.
  - The guarded `markProcessing` gives at-least-once re-pop safety now (FR-11) with no extra query, and is
    already correct for T2.3's "retry from `received`".
  - `Job` seals the envelope schema on the consume side exactly as `encodeJob()` does on the produce side;
    the worker stays free of array keys, consistent with `WebhookEvent` / `VerificationResult`.
  - Infra-fault backstop keeps the `restart: unless-stopped` container from hot crash-looping on a flapping
    dependency, reusing the front controller's log-and-don't-leak discipline.
  - A finite BLPOP timeout leaves T2.5's graceful-shutdown hook a natural place to live (the `null` branch).

- **Negative / debt:**
  - **Interim `failed` over-commits transient faults.** Until T2.3, a transient failure is marked terminally
    `failed` instead of being retried. Documented here and in the worker docblock; it is visible and audited.
  - `webhooks:retry` (zset) and `webhooks:dlq` (list) are referenced in the spec but **not created** in T2.2
    — deliberately. `attempt` flows from the envelope into `WebhookEvent` but is **not** incremented or acted
    on (no retry yet); `markProcessing` does not touch `attempts`. T2.3 owns `attempts++` and the retry math.
  - The 1s infra backoff and the BLPOP timeout are two small constants introduced here; both are infra
    concerns and must not be confused with the T2.3 retry backoff. Called out to prevent that conflation.
  - `Job` and `WebhookEvent` share `provider`/`attempt`. Accepted as intentional: `Job` is the queue
    reference, `WebhookEvent` is the fetched verified row; merging them would pull DB columns into the queue
    layer.
```
