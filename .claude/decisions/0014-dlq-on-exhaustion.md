# 0014. Dead Letter Queue on exhaustion + permanent failure: `Queue::deadLetter()` mechanism, `RetryScheduler::deadLetter()` policy, and DB-before-RPUSH ordering

- **Status:** Accepted
- **Date:** 2026-06-06

## Context

T2.4 fills the **second and last** of the two seams ADR 0012 opened in `app/worker.php` and
ADR 0013 (Decision 5) explicitly pre-committed: "T2.4 replaces the exhaustion fall-through and
the permanent branch with `webhooks:dlq` + `failed`, by addition not rewrite."

Two call sites currently mark an event terminally `failed` WITHOUT pushing it to the dead
letter list — honest, audited (`last_error` is already persisted), but incomplete against FR-8
/ AC-4:

1. **The permanent branch** — `worker.php:142-145`:
   `catch (PermanentHandlerException $e) { $events->markFailed($row['id'], $e->getMessage()); }`.
2. **The exhaustion fall-through** inside `RetryScheduler::retryOrFail()` —
   `RetryScheduler.php:72-76`: when `next = job->attempt() + 1 > MAX_ATTEMPTS`, it calls
   `$events->markFailed($id, $error)` and returns.

Constraints carried in from the existing code:

- `Queue` already **owns** the Redis key names and the JSON envelope schema
  `{event_id, provider, attempt}`, sealed in private `encodeJob()`/`decodeJob()`
  (`Queue.php:33-40`, `Queue.php:190-233`). Its docblock already **reserves** `webhooks:dlq`
  (list, T2.4): `Queue.php:24-25`. The DLQ key has a designated home — it is not yet a constant.
- `markFailed` **already persists `last_error`** (`EventRepository.php:179-187`) and sets
  `status='failed'`. The NEW work of T2.4 is the **DLQ push**; `markFailed` is KEPT, not changed.
- `Queue::enqueue()` (`Queue.php:75-85`) and `Queue::scheduleRetry()` (`Queue.php:138-145`)
  both **throw loudly** rather than silently drop a job (`enqueueFailed` / `scheduleRetryFailed`
  on a `false` Redis return), because a silently dropped job breaks at-least-once delivery.
- `QueueException` is a factory-style typed exception (`enqueueFailed`, `scheduleRetryFailed`,
  `encodeFailed`, `decodeFailed`, `connectionFailed`); messages name at most host:port or the
  event id, never the payload or a secret (NFR-1).
- `markProcessing` is guarded `AND status='received'` (`EventRepository.php:116-126`): a row
  stuck in `processing` can **never** be re-claimed by a future delivery of the same job — it
  is invisible to both the worker and (correctly) to the dashboard's failed view. This makes
  the partial-failure end state of the two writes a real correctness concern.
- The job envelope's `attempt` (`Job::attempt()`, `Job.php:48-52`) is the LAST attempt number
  that ran — for an exhausted job that is `MAX_ATTEMPTS`; for a permanent failure it is whatever
  attempt was running when the handler threw `PermanentHandlerException`.
- Deployment is a **single worker container** (spec NFR/Non-goal, `webhook-handler.spec.md:26`).
- Ordering precedents already set in the codebase: `RetryScheduler::retryOrFail()` does the
  **DB write FIRST**, then the zset write, so a Redis failure leaves the row at the recoverable
  `received` (`RetryScheduler.php:60-63`); `promoteDueRetries()` does **RPUSH-then-ZREM** so a
  crash between the two re-promotes rather than loses (`Queue.php:158-160`).

Scope fence (SOP "do not bleed into later subtasks"): T2.4 is ONLY DLQ-on-exhaustion + the
permanent path. The **DLQ re-queue / drain path is T3.2** (`tasks:29`) and graceful SIGTERM is
**T2.5** (`tasks:25`) — neither is built here. T2.4 only needs to *write* the DLQ list, not
read or drain it.

Three genuine choices arise.

## Options considered

### Decision 1 — WHERE the DLQ push lives

Both seams (exhaustion, permanent) must end identically: persist `failed` + `last_error` AND
push the envelope to `webhooks:dlq`. The push touches a Redis key and re-encodes the envelope.

#### Option A — `Queue::deadLetter()` mechanism + `RetryScheduler::deadLetter()` policy method, called from both seams
- `Queue` gains a low-level `deadLetter(eventId, provider, attempt): void` that `RPUSH`es the
  re-encoded envelope onto a new `private const DLQ = 'webhooks:dlq'` and throws
  `QueueException::deadLetterFailed($eventId)` on a `false` return — the exact mirror of
  `enqueue()`/`scheduleRetry()` (it reuses the sealed `encodeJob()`).
- `RetryScheduler` gains a `deadLetter(EventRepository $events, int $id, Job $job, string $error)`
  that does the **DB write then the RPUSH** (Decision 2), consolidating the two-step terminal
  policy in ONE place. The exhaustion branch of `retryOrFail()` calls it; the worker's permanent
  `catch` calls it too — so BOTH seams are byte-identical, mirroring how T2.3's `retryOrFail()`
  consolidated the two transient branches (ADR 0013, Decision 5).
- Pros: Single ownership preserved on every Redis side — `Queue` owns the `webhooks:dlq` key and
  the envelope on the DLQ side exactly as it does for `webhooks:queue` and `webhooks:retry`
  (the same reason `decodeJob` lives beside `encodeJob`). The terminal *policy* (which DB write,
  in which order, then the push) lives once in `RetryScheduler`, so the exhaustion seam and the
  permanent seam cannot drift apart — the same anti-duplication win T2.3 bought for the transient
  branches. By **addition, not rewrite**: `markFailed` is untouched; `retryOrFail`'s exhaustion
  branch swaps one call (`markFailed` → `deadLetter`); the worker's permanent `catch` swaps one
  call. Matches the spec's named `RetryScheduler` component (`webhook-handler.spec.md:74`).
- Cons: One new method on each of two classes, and `RetryScheduler` now also owns the *terminal*
  step, not only the *retry* step — a slightly broader role than its name suggests. Accepted: it
  is still "the policy half of the failure path"; renaming is not worth it for one task.

#### Option B — push directly from the worker / each site
- The worker's permanent `catch` calls `$queue->...` (or a new `deadLetter`) and writes
  `markFailed` itself; the exhaustion branch inside `retryOrFail` does its own pair.
- Pros: No new policy method; fewer indirections.
- Cons: The two-write terminal sequence (and its ordering, Decision 2) would be duplicated in
  two places and could drift — the exact problem ADR 0013, Decision 5 solved for the transient
  branches by funnelling them through `retryOrFail`. It also re-spreads Redis-key/envelope
  knowledge if the worker pushes directly rather than through a `Queue` method. Rejected: it
  trades the codebase's established consolidation for marginal brevity.

#### Option C — push directly from sites but keep a `Queue::deadLetter()` mechanism (no scheduler method)
- `Queue::deadLetter()` exists (key + envelope sealed), but each seam calls `markFailed` then
  `queue->deadLetter(...)` inline.
- Pros: Keeps the Redis key sealed in `Queue` (fixes half of B's problem).
- Cons: Still duplicates the *ordering* decision (DB-then-RPUSH) and the pairing at two sites.
  The exhaustion site is already inside `RetryScheduler`; the permanent site is in the worker —
  so the pair lives in two files with no single definition. Rejected in favour of A's single
  policy method, which is the smaller delta given `retryOrFail` already established the pattern.

### Decision 2 — ORDERING of `markFailed` (DB) vs the DLQ `RPUSH`

Two writes, no cross-store transaction. A crash or a thrown `QueueException` between them leaves
a partial state. We must choose which is durable first and accept the better partial state.

The two candidate orderings and their partial-failure end states:

- **DB-first** (markFailed succeeds, RPUSH then fails/crashes): row is terminal `failed` with
  `last_error`; the envelope is **absent from `webhooks:dlq`**. The dashboard's failed view (it
  reads `status`, FR-9/NFR-4) shows the failure correctly; only the DLQ *list* is missing one
  entry. The job is already gone from the main queue (it was BLPOP'd), so nothing reprocesses.
- **RPUSH-first** (RPUSH succeeds, markFailed then fails/crashes): the envelope is in
  `webhooks:dlq`, but the row is **stuck in `processing`** (the worker had claimed it via the
  guarded `markProcessing`). Because `markProcessing` requires `status='received'`, that row can
  **never** be re-claimed and never reaches a terminal state — it is invisible to the dashboard's
  failed view and silently orphaned. The DLQ now also holds an entry whose DB row lies.

#### Option D — DB write FIRST, then RPUSH (the same order as `retryOrFail`)
- Pros: The worse store to lose is the DLQ *list*, not the *audit row*. The audit row is the
  source of truth (NFR-4: "every state transition recorded"); a `failed`-but-not-in-DLQ row is
  observable, terminal, and explainable, whereas a stuck-`processing` row is an invisible,
  un-reclaimable orphan. Consistent with `retryOrFail`'s explicit "DB write comes FIRST"
  (`RetryScheduler.php:60-63`) so the failure path has ONE ordering rule throughout. If the
  RPUSH throws, the loud `QueueException` is logged by the worker's outer backstop — the absence
  is recorded, not silent.
- Cons: A crash in the gap yields `failed`-without-DLQ-entry. Mitigated: this is recoverable and
  visible (the dashboard reads `status`); the T3.2 drain path can reconcile from `status='failed'`
  rows if ever needed. Strictly better than the alternative's invisible orphan.

#### Option E — RPUSH first, then DB write
- Pros: Guarantees the DLQ list is the durable record of every exhausted job.
- Cons: Its partial state is the **stuck-`processing` orphan** described above — un-reclaimable
  because of the `markProcessing` guard, invisible to the dashboard, and contradicting the
  audit-as-source-of-truth principle. Worse end state than D. Rejected.

(Note: this is the *opposite* tilt from `promoteDueRetries`' RPUSH-then-ZREM, and deliberately so.
There, both stores are Redis and re-doing the move is harmless idempotent reprocessing; here the
DB row is the authoritative audit record and a stuck-`processing` orphan is a genuine, invisible
defect, so the durable-first store must be the DB.)

### Decision 3 — does `Queue::deadLetter()` throw on RPUSH failure, or swallow it?

#### Option F — throw `QueueException::deadLetterFailed($eventId)` on a `false` RPUSH return
- Pros: Identical discipline to `enqueue()` and `scheduleRetry()` — a queue NEVER silently drops
  a job (`Queue.php:77-84`, `Queue.php:142-144`). The throw propagates to the worker's outer
  backstop, which logs it (full detail to the error log, never leaked) and backs off — so a
  failed DLQ push is *observed*, not lost. With DB-first ordering (Decision 2) the row is already
  `failed`, so the throw does not corrupt state; it only records that the list write needs
  attention. Factory message names only the event id (NFR-1).
- Cons: A failed DLQ push surfaces as a logged infra fault rather than a clean terminal. Correct:
  losing a job silently is the worse outcome the whole queue layer is built to prevent.

#### Option G — log and swallow (best-effort DLQ)
- Cons: Breaks the queue layer's one invariant (no silent drops) for the *one* list whose entire
  purpose is to not lose permanently-failed jobs. A DLQ that silently loses entries is worse than
  no DLQ. Rejected.

### Decision 4 — what `attempt` value the DLQ envelope carries

#### Option H — carry `job->attempt()` (the last attempt number that actually ran)
- For an **exhausted** transient: that is `MAX_ATTEMPTS` (= 3), the last attempt that failed.
  (The exhaustion branch is reached when `job->attempt() + 1 > MAX_ATTEMPTS`, i.e.
  `job->attempt() == MAX_ATTEMPTS`.) For a **permanent** failure: it is the attempt the handler
  threw on (often 1, but n if a retry later turned permanent).
- Pros: The DLQ entry truthfully records "this many attempts were spent", which is exactly what
  a human draining the DLQ (T3.2) or the dashboard's DLQ view (FR-10) wants to see. It matches
  the DB `attempts` column already written by `markForRetry` for the retried path, so envelope
  and DB stay coherent (the same coherence principle as ADR 0013, Decision 3). No new increment.
- Cons: For a permanent first-attempt failure the envelope says `attempt=1`, which could read as
  "not yet tried" to a careless reader — but it is literally true (one attempt, which failed
  permanently), and the DB `last_error` + `status='failed'` disambiguate. Accepted.

#### Option I — carry `MAX_ATTEMPTS` always (a sentinel "exhausted" marker)
- Cons: Lies for a permanent failure that only ran once; loses the real attempt count the human
  draining the DLQ wants. Rejected.

## Decision

- **D1: Option A** — add `Queue::deadLetter(eventId, provider, attempt): void` (a new
  `private const DLQ = 'webhooks:dlq'`; RPUSH of the re-encoded envelope via the sealed
  `encodeJob()`), and `RetryScheduler::deadLetter(EventRepository $events, int $id, Job $job,
  string $error): void` (the consolidated terminal policy: DB write then push). Both seams —
  the exhaustion branch of `retryOrFail()` and the worker's permanent `catch` — route through
  `RetryScheduler::deadLetter()`, so they stay byte-identical, mirroring T2.3's `retryOrFail`.
  `Queue` stays the single owner of the `webhooks:dlq` key + envelope.
- **D2: Option D** — **DB write FIRST, then RPUSH**, the same ordering as `retryOrFail`. The
  audit row is the source of truth; the tolerable partial state is `failed`-without-DLQ-entry
  (visible, terminal, reconcilable), never the un-reclaimable stuck-`processing` orphan.
- **D3: Option F** — `Queue::deadLetter()` **throws** `QueueException::deadLetterFailed($eventId)`
  on a `false` RPUSH, consistent with `enqueue`/`scheduleRetry`; the worker's outer backstop
  logs and backs off. No silent drop.
- **D4: Option H** — the DLQ envelope carries `job->attempt()`, the **last attempt number that
  ran** (`MAX_ATTEMPTS` for exhaustion, the throwing attempt for permanent). Truthful, coherent
  with the DB `attempts` column, no extra increment.

### Terminal failure state machine (T2.4 addition to ADR 0013's machine)

```
                                          attempts EXHAUSTED (next > MAX_ATTEMPTS)
received --markProcessing--> processing --transient/other ----------------------+
                                 |                                               |
                                 | Permanent (any attempt) ----------------------+--> RetryScheduler::deadLetter:
                                 |                                                     1. events->markFailed(id, error)   [DB FIRST]
                                 |                                                     2. queue->deadLetter(eventId,        [then RPUSH]
                                 v                                                        provider, attempt)
                              processed                                                = status 'failed' + last_error + envelope on webhooks:dlq
```

### Worker / scheduler diff (structure only — no feature code)

```
// RetryScheduler::retryOrFail() exhaustion branch — ONE call swapped:
if ($next > self::MAX_ATTEMPTS) {
    $this->deadLetter($events, $id, $job, $error);   // was: $events->markFailed($id, $error);
    return;
}

// RetryScheduler::deadLetter() — NEW consolidated terminal policy:
$events->markFailed($id, $error);                                          // D2: DB FIRST
$this->queue->deadLetter($job->eventId(), $job->provider(), $job->attempt()); // then RPUSH (D4 attempt)

// worker.php permanent catch — ONE call swapped:
} catch (PermanentHandlerException $e) {
    $scheduler->deadLetter($events, $row['id'], $job, $e->getMessage());   // was: $events->markFailed(...)
    continue;
}

// Queue::deadLetter() — NEW mechanism, mirror of enqueue():
$length = $this->redis->rPush(self::DLQ, $this->encodeJob($eventId, $provider, $attempt));
if ($length === false) { throw QueueException::deadLetterFailed($eventId); }   // D3
```

`markFailed` is UNCHANGED (it already persists `last_error` + `status='failed'`). The two
transient branches and the happy path are UNCHANGED. The only edits are: one new `Queue` method
+ constant, one new `QueueException` factory, one new `RetryScheduler` method, and two one-line
call-site swaps. By addition, not rewrite.

## Consequences

- **Positive:**
  - **FR-8 / AC-4 satisfied:** after 3 failed attempts (or a permanent error) the event lands in
    `webhooks:dlq` AND is `status='failed'` with `last_error` — the second seam ADR 0012 left,
    now closed.
  - `Queue` remains the single owner of all three Redis keys + the envelope on every side
    (`queue`, `retry`, `dlq`), consistent with `encodeJob`/`decodeJob`.
  - The terminal failure policy lives ONCE in `RetryScheduler::deadLetter()`, so the exhaustion
    and permanent seams cannot drift — the same consolidation win as `retryOrFail` for transients.
  - DB-first ordering gives the strictly-better partial-failure state (visible `failed`, never an
    invisible stuck-`processing` orphan) and reuses the failure path's single ordering rule.
  - No silent drops: a failed DLQ push throws and is logged by the existing backstop (NFR-4).
  - DLQ envelope carries the true attempt count, coherent with the DB `attempts` column and ready
    for the T3.2 drain/dashboard to display.
  - `markFailed` is untouched and the change is small and additive — minimal review surface.

- **Negative / debt:**
  - **No cross-store transaction:** a crash in the gap between `markFailed` and the RPUSH yields a
    `failed` row without a DLQ entry. Chosen as the lesser evil (visible + reconcilable from
    `status='failed'`); the T3.2 drain path can reconcile if ever needed. Documented at the method.
  - `RetryScheduler` now owns the terminal step too, slightly stretching its "retry policy" name.
    Accepted; not worth a rename for one method.
  - The DLQ is **write-only in T2.4** — nothing drains or re-queues it yet. That is **T3.2**
    (`tasks:29`) by design; this task deliberately does not read the list.
  - Single-worker assumption carries forward (no new concurrency introduced; `deadLetter` is a
    plain RPUSH, no read-modify-write).
```
