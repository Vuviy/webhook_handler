# 0013. Retry scheduler: exponential backoff, the `webhooks:retry` zset, and the attempt counter

- **Status:** Accepted
- **Date:** 2026-06-06

## Context

T2.3 fills the first of the two seams ADR 0012 left in `app/worker.php`. T2.2 shipped a
working happy path and a *defined-but-over-committing* failure path: the three handler-failure
`catch` blocks (`worker.php:128-143`) all call `markFailed()`, terminally marking even a
transient fault as `failed`. ADR 0012 (Decision 3) names these blocks the exact insertion
points — T2.3 reroutes the **transient** and **catch-all** branches to "schedule a retry / mark
`received`"; T2.4 later reroutes the **permanent** and **exhausted** branches to the DLQ.

The spec's worker flow (`webhook-handler.spec.md:60-62`) and the skill
(`queue-processing/SKILL.md:27-34`) are explicit:

- transient fail → schedule retry at `now + 2^(n-1)·base` with jitter, `attempts++`;
- `attempt > 3` (exhaustion) or permanent → `webhooks:dlq`, `status=failed`;
- max **3 attempts** (FR-7); AC-3 wants growing delays.

Constraints carried in from the existing code:

- `Queue` already **owns** the Redis key names and the JSON envelope schema
  `{event_id, provider, attempt}`, sealed in private `encodeJob()`/`decodeJob()`
  (`Queue.php:32`, `Queue.php:118-161`). Its docblock already RESERVES `webhooks:retry`
  (zset, T2.3) and `webhooks:dlq` (list, T2.4) (`Queue.php:22-24`).
- The envelope `attempt` field flows through (`Job.php:48-52`) but is **not incremented or
  acted on** yet (`Job.php:24-25`).
- The DB has `attempts INT UNSIGNED NOT NULL DEFAULT 0` (migration 0001) that **nothing
  writes** today. `markProcessing` deliberately does not touch it (`EventRepository.php:113`).
- `markProcessing` is guarded `AND status='received'` (`EventRepository.php:116-126`) — so a
  retried job MUST be back at `received` to win the claim again. ADR 0012 (Decision 2) already
  anticipated this: "a retried job is back at `received`, so the claim succeeds again."
- Deployment is a **single worker container** (`docker-compose.yml`, spec NFR/Non-goal:
  "single worker container is enough"; `webhook-handler.spec.md:26`).
- The loop has a finite BLPOP timeout (`BLPOP_TIMEOUT_SECONDS = 5`) with a `$job === null`
  idle tick (`worker.php:74-79`), and an outer infra backstop. Open question #2 of the spec
  (`webhook-handler.spec.md:99`) — backoff in seconds vs minutes — is still unresolved.

Six genuine choices arise.

## Options considered

### Decision 1 — who promotes due jobs from `webhooks:retry` back to `webhooks:queue`

The retry zset is scored by ready-at unix time; something must move entries whose score has
passed back onto the main list so the worker re-pops them.

#### Option A — promote inside the worker loop (on each idle tick / before each BLPOP)
- Pros: No new process, container, or lifecycle. The single worker is the *only* consumer, so
  it can cheaply sweep the zset on each iteration (and especially on the `$job === null` idle
  tick, which already exists at `worker.php:76-79`). One place to reason about, one place for
  T2.5 to shut down. No second connection. Promotion latency is bounded by `BLPOP_TIMEOUT`
  (≤5s when idle; immediately before each blocking pop when busy).
- Cons: Promotion only runs as often as the loop turns. Under a permanent flood of main-queue
  jobs the loop could block in BLPOP and delay a sweep — bounded by the 5s timeout, acceptable
  for this scale. Couples retry promotion to the worker's liveness (if the worker is down,
  nothing is processed *anyway*, so retries waiting is correct, not a bug).

#### Option B — a separate scheduler process / container
- Pros: Decouples promotion cadence from processing; the classic design for many workers.
- Cons: A second long-running process to write, deploy, health-check, and shut down (a second
  T2.5). Two consumers of the zset reintroduce the promotion race (Decision 2) for no benefit
  at single-worker scale. Explicit overkill for a system whose spec declares one worker enough
  (`webhook-handler.spec.md:26`). YAGNI.

### Decision 2 — atomicity of promotion (the ZRANGEBYSCORE + ZREM race)

Reading due members then ZREM-ing them is not atomic: with two consumers, both could read the
same member and both requeue it (a duplicate). With one consumer, the window does not exist.

#### Option C — accept the race (non-atomic ZRANGEBYSCORE then ZREM), single-worker
- Pros: Trivial phpredis calls, no Lua. Correct for the single-worker deployment we actually
  run: there is exactly one sweeper, so no concurrent reader exists. And even if a duplicate
  *did* slip through, at-least-once + the guarded `markProcessing` (`EventRepository.php:116`)
  already make a double-promotion harmless (the second claim matches 0 rows and is skipped).
- Cons: Not safe if a second worker is ever added. Mitigated by writing the promotion as one
  small method with a docblock that names the assumption, so hardening to a Lua `ZPOPMIN`-style
  atomic pop is a localized change, not a redesign.

#### Option D — Lua script for atomic claim-and-remove
- Pros: Multi-worker safe today.
- Cons: Buys safety the single-worker deployment does not need, adds an embedded Lua string to
  maintain and test. Premature. Note the *path* but do not build it.

### Decision 3 — source of truth for the attempt count (envelope vs DB column vs both)

The spec wants both an envelope `attempt` that grows across requeues AND a DB `attempts` that
is incremented (`webhook-handler.spec.md:62`, `queue-processing/SKILL.md:28`).

#### Option E — envelope is the control value; DB `attempts` is the audit mirror
The **envelope `attempt`** drives the retry decision (it is what's on the wire and survives a
DB hiccup). On a transient failure of attempt *n*, the worker requeues into the zset with
`attempt = n + 1` and writes that same `n + 1` to the DB `attempts` column in the same
`markForRetry` call, so the dashboard sees the true try count. The two stay coherent because
the increment happens once, at one site, and both sinks receive the *same* number.
- Pros: One increment, two coherent sinks. The envelope (control) is authoritative for the
  branch decision, matching how the job already carries `attempt` (`Job.php:48-52`); the DB
  (audit) is for humans/the dashboard (FR-9, NFR-4). At-least-once safe: a re-popped *stale*
  job carrying the old `attempt` is caught by the guarded claim, not by counter arithmetic.
- Cons: Two representations to keep equal. Mitigated by incrementing in exactly one place.

#### Option F — DB column is the single source of truth (read it back to decide)
- Pros: One authority.
- Cons: Forces an extra read of `attempts` on the failure path, and makes the retry decision
  depend on a DB round-trip rather than the value already in hand on the envelope. More
  coupling, no gain at this scale.

#### Option G — envelope only; never touch the DB column
- Pros: Simplest.
- Cons: Leaves `attempts` at 0 forever, so the dashboard (T3.x, FR-9) can never show "tried 2
  of 3". The spec explicitly wants `attempts++` in the DB. Rejected.

### Decision 4 — `RetryScheduler` as a separate class vs methods on `Queue`

The spec's component list names a `RetryScheduler` (`webhook-handler.spec.md:74`). The question
is where the *policy* (backoff math, jitter, the `attempt > 3` boundary) lives vs the *mechanism*
(the raw ZADD / ZRANGEBYSCORE / ZREM, which touch Redis keys `Queue` owns).

#### Option H — a `RetryScheduler` that owns POLICY, depending on a `Queue` that owns MECHANISM
- `Queue` gains the low-level zset mechanism (it already owns the key names and the envelope —
  the same reason it owns `encodeJob`/`decodeJob`): a `scheduleRetry(eventId, provider, attempt,
  readyAt)` that ZADDs the *re-encoded envelope* with the ready-at score, and a
  `promoteDueRetries(now): int` that moves due members back to `webhooks:queue`.
- `RetryScheduler` owns POLICY: `nextAttempt()` / exhaustion check, and `delayFor(attempt):
  int` (the `2^(n-1)·base + jitter` math). The worker asks the scheduler for the delay and
  whether attempts remain, then calls `Queue::scheduleRetry(...)`.
- Pros: Clean separation along the line the codebase already draws — `Queue` is the sealed
  owner of Redis keys + envelope on BOTH the enqueue and now the retry side (consistent with
  why `decodeJob` lives next to `encodeJob`, ADR 0012). The *policy* (numbers, jitter,
  exhaustion) is isolated, unit-testable without Redis, and is the single place the open
  backoff question is answered. Matches the spec's named component.
- Cons: One more class and one more collaborator wired into the worker. Accepted: it is the
  seam the spec asked for, and it keeps "magic numbers" out of `Queue`.

#### Option I — put everything (math + zset ops) on `Queue`
- Pros: One class.
- Cons: Drags backoff *policy* (base, jitter, the 3-attempt rule) into the queue mechanism,
  the exact mixing ADR 0012 avoided by keeping the worker's processing policy out of `Queue`.
  Contradicts the spec's component list. Rejected.

#### Option J — put everything on a `RetryScheduler`, bypassing `Queue` for the zset
- Cons: A second class would then hold Redis key names and re-encode the envelope, breaking the
  single-ownership `Queue` enforces (`Queue.php:16-20`). The wire schema would no longer be
  sealed in one place. Rejected.

### Decision 5 — the exact T2.3 / T2.4 boundary (exhaustion & permanent in this task)

T2.3 owns "attempts remain → schedule retry". T2.4 owns the DLQ. What does T2.3 do at
**exhaustion** (would-be attempt > 3) and on a **permanent** error, given the DLQ does not
exist yet?

#### Option K — T2.3 leaves exhausted + permanent as interim `markFailed` (documented debt)
Exactly mirroring how T2.2 left the *transient* branch. T2.3 changes only the transient and
catch-all branches to "schedule retry while attempts remain; on exhaustion fall through to the
interim `markFailed`". The permanent branch stays `markFailed`. T2.4 then replaces both the
exhaustion fall-through and the permanent branch with `webhooks:dlq` + `failed`.
- Pros: Same honest-but-minimal discipline T2.2 used; the seam stays clean and later tasks fill
  bodies **by addition, not rewrite**. Exhaustion is still terminal and audited (`last_error`),
  just not yet in the DLQ list. No machinery from T2.4 leaks into T2.3.
- Cons: For one task, an exhausted retry is `failed`-in-DB but not yet *in the DLQ list*. Known,
  documented, visible (the dashboard reads `status`, not the list). Identical, accepted trade
  to ADR 0012's interim `failed`.

#### Option L — T2.3 builds the DLQ now so exhaustion is "complete"
- Cons: That is literally T2.4. Bleeds scope, violates the SOP's "do not bleed into later
  subtasks." Rejected.

### Decision 6 — backoff constants, unit, and jitter (resolves spec open question #2)

#### Option M — base 2s, seconds unit, **equal jitter**: delay = `D/2 + rand(0, D/2)`, `D = 2^(n-1)·2`
- Concrete: nominal `D` = 2s / 4s / 8s for attempts after try 1 / 2 / 3; with equal jitter the
  actual delay is 1-2s, 2-4s, 4-8s respectively.
- Pros: Seconds match the spec/skill's own example (`queue-processing/SKILL.md:30`) and a
  *webhook* workload, where downstream blips recover in seconds, not minutes; minutes would
  leave providers' own retries racing ours. **Equal jitter** keeps a guaranteed minimum spacing
  (never 0) while still spreading the herd — safer than *full* jitter (`rand(0, D)`), which can
  collapse to near-zero delay and re-stampede. Constants live as `RetryScheduler` class
  constants (`BASE_DELAY_SECONDS = 2`, `MAX_ATTEMPTS = 3`), the single place to tune them.
- Cons: Seconds-scale delays assume fast-recovering downstreams; a genuinely minutes-long
  outage exhausts all 3 attempts and DLQs quickly. Acceptable: the DLQ + re-queue path (T3.2)
  is the designed recovery for that, not a longer in-line backoff.

#### Option N — minutes unit (2/4/8 min)
- Cons: A webhook held minutes invites the provider's own redelivery to duplicate work, and
  slows the test loop for AC-3. Rejected for this workload.

#### Option O — full jitter (`rand(0, D)`)
- Cons: Can return ~0, defeating the minimum-spacing intent and re-stampeding. Equal jitter is
  the better default here.

## Decision

- **D1: Option A** — promote due retries **inside the worker loop**, swept once per iteration
  (notably on the existing `$job === null` idle tick, `worker.php:76-79`), before blocking on
  BLPOP. No separate process. Single-worker reality + T2.5 owning one lifecycle make this the
  right size.
- **D2: Option C** — **accept the non-atomic** ZRANGEBYSCORE-then-ZREM promotion for the single
  worker; the guarded `markProcessing` already neutralizes a stray double-promotion. Document
  the assumption in the method so a future Lua `ZPOPMIN` hardening (Option D) is localized.
- **D3: Option E** — the **envelope `attempt` is the control value**, the **DB `attempts` is
  the audit mirror**; both are set from the *same* incremented number in one `markForRetry`
  call, so they stay coherent. No extra read.
- **D4: Option H** — add a `RetryScheduler` that owns **policy** (`delayFor()`, exhaustion /
  `MAX_ATTEMPTS`, jitter), depending on a `Queue` that owns the **mechanism** (`scheduleRetry()`
  ZADD of the re-encoded envelope; `promoteDueRetries()` zset→list move). Keeps Redis keys +
  envelope sealed in `Queue`, keeps numbers out of `Queue`, matches the spec's named component.
- **D5: Option K** — T2.3 changes **only** the transient + catch-all branches to "schedule
  retry while attempts remain; on exhaustion, interim `markFailed` (documented debt)". The
  permanent branch stays interim `markFailed`. **T2.4** replaces the exhaustion fall-through and
  the permanent branch with `webhooks:dlq` + `failed`. By addition, not rewrite. No DLQ in T2.3.
- **D6: Option M** — **base 2s, seconds**, **equal jitter** `D/2 + rand(0, D/2)` with
  `D = 2^(n-1)·2`. Delays after try 1/2/3 = 1-2s / 2-4s / 4-8s. `MAX_ATTEMPTS = 3`. This
  **resolves spec open question #2 in favour of seconds.**

### Retry status state machine (T2.3 addition to ADR 0012's machine)

```
received --markProcessing(guarded)--> processing --handler ok--> processed
   ^                                       |
   |  markForRetry(id, attempt, error):    | handler throws Transient | other Throwable
   |  status back to 'received',           v          (attempts remain)
   |  attempts = attempt, last_error set   |---> scheduleRetry(zset, readyAt) ; back to 'received'
   |                                       |
   |  (promoteDueRetries moves the         | Transient|other AND attempts EXHAUSTED  -> markFailed (interim; T2.4 -> DLQ)
   |   envelope zset->queue when due)      | Permanent (any attempt)                 -> markFailed (interim; T2.4 -> DLQ)
```

### Worker wiring diff (structure only — no feature code)

```
$scheduler = new RetryScheduler($queue);          // new collaborator, built once before the loop

while (true) {
  try {
    $scheduler->promoteDue();                      // D1: sweep due retries -> webhooks:queue
    $job = $queue->consume(BLPOP_TIMEOUT_SECONDS);
    if ($job === null) { continue; }               // idle tick (T2.5 lands here too)
    ... findForProcessing / markProcessing guard / build WebhookEvent (unchanged) ...

    try {
      $handler->handle($event);
    } catch (TransientHandlerException $e) {        // CHANGED by T2.3
      $scheduler->retryOrFail($events, $row['id'], $job, $e->getMessage());
    } catch (PermanentHandlerException $e) {        // UNCHANGED (interim markFailed; T2.4 -> DLQ)
      $events->markFailed($row['id'], $e->getMessage());
    } catch (\Throwable $e) {                       // CHANGED by T2.3 (catch-all = transient)
      $scheduler->retryOrFail($events, $row['id'], $job, $e->getMessage());
    }
    $events->markProcessed($row['id']);             // only on normal return (unchanged)
  } catch (\Throwable $e) { error_log((string) $e); sleep(INFRA_BACKOFF_SECONDS); }  // unchanged
}
```

`retryOrFail()` encapsulates the boundary so the two transient branches stay identical:
if `nextAttempt = job->attempt + 1 <= MAX_ATTEMPTS` → `events->markForRetry(id, nextAttempt,
error)` + `queue->scheduleRetry(job->eventId, job->provider, nextAttempt, now + delayFor(nextAttempt))`;
else (exhausted) → interim `events->markFailed(id, error)` (the T2.4 seam).

## Consequences

- **Positive:**
  - AC-3 satisfied: a transiently-failing handler is retried up to 3 times with growing,
    jittered delays (1-2s / 2-4s / 4-8s), driven by the envelope `attempt`.
  - The T2.2 seam closes by **addition**: only two `catch` bodies and the loop's pre-BLPOP
    sweep change; the loop shape, `Job`, and the `Handler` interface are untouched.
  - `Queue` stays the single owner of Redis keys + envelope (now on the retry side too,
    consistent with `encodeJob`/`decodeJob`); backoff *policy* is isolated and Redis-free to
    unit-test in `RetryScheduler`.
  - The guarded `markProcessing` already makes a re-popped or double-promoted retry safe — no
    new dedupe needed. `attempts` finally gets written, so the dashboard can show true try
    counts (FR-9).
  - Spec open question #2 is answered (seconds), with the numbers in one constant.

- **Negative / debt:**
  - **D2 promotion is non-atomic** — correct only because there is one worker. A second worker
    needs the Lua `ZPOPMIN` hardening (Option D); the assumption is documented at the method.
  - **D5 interim `failed` persists for exhausted + permanent** until T2.4: an exhausted retry is
    `status=failed` in the DB but **not yet in `webhooks:dlq`**. Honest, audited (`last_error`),
    visible — and exactly the trade ADR 0012 already accepted. T2.4 removes it.
  - Two representations of the attempt count (envelope + DB) must stay coherent; guaranteed by a
    single increment site (`retryOrFail`) feeding both.
  - Seconds-scale backoff assumes fast-recovering downstreams; a minutes-long outage exhausts
    retries quickly and relies on the DLQ + re-queue path (T2.4 / T3.2), not a longer in-line wait.
```
