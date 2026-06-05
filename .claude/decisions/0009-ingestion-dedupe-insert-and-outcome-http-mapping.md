# 0009. Ingestion: dedupe-insert strategy, outcome→HTTP mapping, and insert-then-enqueue failure semantics

- **Status:** Accepted
- **Date:** 2026-06-05

## Context

T1.5 builds `App\Http\IngestionController` — the integration piece that ties the
three verifiers (T1.2–T1.4), the DB (`webhook_events`, T0.3) and the Redis queue
(T0.4) together for a webhook of a **known** provider. The spec HTTP flow
(`webhook-handler.spec.md:55-57`) is:

> read raw body → select verifier → verify → dedupe by event id →
> insert `webhook_events (status=received)` → `RPUSH webhooks:queue` → `202`.

Three decisions in that one sentence are non-trivial and need recording, because
they are load-bearing for correctness (idempotency, at-least-once) and are hard to
change later once the worker (T2.x) depends on the row/queue contract:

1. **How do we dedupe-insert atomically?** `webhook_events` has
   `UNIQUE KEY uq_provider_event (provider, event_id)`
   (`migrations/0001_create_webhook_events.sql:27`), and the migration explicitly
   anticipates `INSERT ... ON DUPLICATE KEY` here
   (`0001_create_webhook_events.sql:10-12`). Delivery is at-least-once, so the SAME
   provider event id can arrive **concurrently** (two FPM workers racing on the same
   redelivery). AC-6 (`webhook-handler.spec.md:87`): a re-delivered event id must NOT
   double-process and must STILL return `2xx`. So the controller must learn, from the
   insert itself, **whether the row was newly inserted** (→ enqueue) or already existed
   (→ do not enqueue, still 202), with no read-modify-write race.

2. **What is the outcome→HTTP mapping?** The verifier returns a three-state
   `VerificationOutcome` (Valid | Invalid | Error,
   `Verification/VerificationOutcome.php`) and `fromSecrets()` can throw
   `VerificationException` for misconfiguration (`Exception/VerificationException.php`).
   Each must map to a distinct, correct HTTP status that drives provider retry
   behaviour the right way.

3. **What happens if `enqueue()` throws AFTER the row is inserted?** The spec order is
   insert-then-enqueue. `Queue::enqueue()` throws `QueueException` if Redis is
   unreachable (`Queue/Queue.php:67-77`). That leaves a `received` row that is never
   enqueued — an orphan. We must decide the response and the residual risk without
   over-engineering a two-phase commit.

Constraints carried in from existing code:
- Verifiers take a `string $rawBody` + **lower-cased** header map; the controller owns
  normalisation (`Verification/ProviderVerifier.php:29-34`).
- `verify()` never throws on routine/malformed input; only `fromSecrets()` throws
  (`ProviderVerifier.php:36-47`).
- `VerificationResult::valid()` guarantees non-empty `eventId`/`eventType`
  (`Verification/VerificationResult.php:40-53`) — the persisted columns are safe.
- `Connection::fromConfig()` returns a plain `\PDO` (ERRMODE_EXCEPTION,
  EMULATE_PREPARES=false) (`Database/Connection.php:21-41`) — prepared statements only.
- NFR-1: never echo secrets or payload in a response (`webhook-handler.spec.md:44-46`).

Scope: T1.5 is the controller for a KNOWN provider plus the supporting
`VerifierRegistry` and `EventRepository`. URL routing, method enforcement (405),
unknown-provider (404) and `index.php` wiring are **T1.6** and are out of scope here.

## Options considered

### 1. Dedupe-insert strategy

#### Option A — `INSERT ... ON DUPLICATE KEY UPDATE id = id`, read `rowCount()`
A single atomic statement. MySQL's `rowCount()` for `INSERT ... ON DUPLICATE KEY
UPDATE` returns **1** when a row was inserted, **2** when an existing row was actually
updated, and **0** when an existing row matched but the UPDATE changed nothing. By
making the UPDATE a deliberate no-op (`id = id`), an existing row can never report 2 —
a duplicate yields **0**, a fresh insert yields **1**. So `rowCount() === 1` is an
exact, race-free "was newly inserted" signal.
- Pros: Atomic — no SELECT-then-INSERT window, correct under concurrent identical
  deliveries. One round-trip. The `rowCount` 1-vs-0 split is exactly the
  enqueue/don't-enqueue signal the controller needs. Matches the migration's stated
  intent (`0001_create_webhook_events.sql:10-12`).
- Cons: The `id = id` no-op is a small idiom that needs a comment so a future reader
  does not "tidy" it away. Touching a duplicate still takes the unique-key lock briefly
  (negligible here).

#### Option B — `INSERT IGNORE`, inspect affected rows
`INSERT IGNORE` skips the row on a duplicate-key collision; `rowCount()` is 1 on insert,
0 on a suppressed duplicate.
- Pros: Also atomic; the affected-rows split is the same 1-vs-0 signal.
- Cons: `IGNORE` downgrades a **broad** class of errors to warnings — not only
  duplicate-key, but also data-truncation, bad-NULL, type-coercion. A genuinely
  malformed row (e.g. an over-long `event_id`) would be silently dropped and the
  controller would treat it as a duplicate, returning 202 for an event that was never
  stored or enqueued — a silent data-loss hole. We want loud failures
  (`Connection.php:30` sets ERRMODE_EXCEPTION precisely for this). Rejected: the
  blast radius of suppressed errors is wrong for an audit log.

#### Option C — SELECT, then INSERT if absent
- Pros: Reads naturally.
- Cons: **Racy.** Two concurrent identical redeliveries both SELECT "absent", both
  INSERT, one hits the unique key and errors — and we are back to handling the duplicate
  anyway, now with an exception instead of a clean signal. Two round-trips for the
  common case. Rejected on the race alone (it is the exact at-least-once scenario AC-6
  targets).

### 2. Outcome → HTTP status mapping

#### Option D — Collapse Error into 401/4xx
- Pros: Fewer status codes.
- Cons: Destroys the distinction `VerificationOutcome::Error` exists for (ADR 0008): a
  PayPal outage is OUR fault and must invite a retry (5xx), not tell a legitimate sender
  "your signature is bad" (401) and drop the event. Rejected.

#### Option E — One status per outcome, retry-aware
Map each outcome to the status that makes the provider's own retry behaviour correct.
- Pros: Honest semantics; lets provider retries + our dedupe combine into at-least-once.
- Cons: None material — it is just the careful mapping. Chosen.

### 3. Insert-then-enqueue failure semantics

#### Option F — Insert, then enqueue; if enqueue throws → 500, leave the `received` row
- Pros: Simple, matches the spec order. The provider sees a non-2xx and **redelivers**;
  on redelivery the dedupe insert reports "duplicate" (row already `received`), and we
  re-attempt the enqueue — so the orphan self-heals via the provider's retry, no
  bespoke reconciliation needed for T1.5. Uses at-least-once as the recovery mechanism
  it already is.
- Cons: A `received` row can briefly exist that was never enqueued (an orphan) if the
  provider never retries. Accepted as a known, low-probability gap (Redis-down is rare
  and short; a later sweeper/reconciler can re-enqueue stale `received` rows — noted as
  debt, not built now).

#### Option G — Enqueue, then insert
- Pros: No orphan `received` row.
- Cons: Inverts the failure into a worse one — a job in the queue with **no DB row** to
  read (the worker re-reads the source of record by event id, `Queue.php:24-28`), so the
  worker would pop a job pointing at nothing. And dedupe lives in the DB, so
  enqueue-first loses the "is this new?" signal that decides whether to enqueue at all.
  Rejected.

#### Option H — Wrap in a DB transaction / two-phase commit across MySQL+Redis
- Pros: Atomicity.
- Cons: Redis and MySQL cannot share a transaction; a real 2PC/outbox is far beyond a
  T1.5-sized task and the spec ("don't over-engineer"). Rejected for now; the outbox
  pattern is the principled future answer if orphans ever matter.

## Decision

**Dedupe-insert: Option A.** `EventRepository::insertReceived()` runs:

```sql
INSERT INTO webhook_events (provider, event_id, event_type, payload, signature_valid, status)
VALUES (:provider, :event_id, :event_type, :payload, 1, 'received')
ON DUPLICATE KEY UPDATE id = id
```

and returns `bool $inserted = ($stmt->rowCount() === 1)`. `id = id` is a deliberate
no-op so a duplicate reports `rowCount() === 0` (never 2). `true` ⇒ newly inserted ⇒
enqueue. `false` ⇒ duplicate (already seen) ⇒ do NOT enqueue. Both still return **202**
(AC-6). All values are bound parameters (server-side prepared, `Connection.php:32`).

**Outcome → HTTP: Option E.** The controller maps:

| Situation | Source | HTTP | Enqueue? | Persist? |
| --- | --- | --- | --- | --- |
| Valid, newly inserted | `Outcome::Valid` + `inserted=true` | **202** | yes | yes (`received`) |
| Valid, duplicate (re-delivery) | `Outcome::Valid` + `inserted=false` | **202** | **no** | already present |
| Invalid (forged/missing/malformed sig) | `Outcome::Invalid` | **401** | no | **no** (see below) |
| Error (remote verify outage, PayPal) | `Outcome::Error` | **502** | no | no |
| Misconfiguration (secret/config absent) | `VerificationException` from `fromSecrets()` | **500** | no | no |
| Unknown provider | (T1.6's concern) | **404** | — | — |

Response bodies are **minimal** and contain no secrets/payload (NFR-1). A tiny JSON
`{"status":"accepted"}` / `{"status":"rejected"}` (or empty) — providers ignore the
body; the **status code** is the contract. The controller returns these as a value
object (`IngestionResponse{int $statusCode, ?string $body}`); it does NOT write to PHP
globals — the front controller (T1.6) renders it. This keeps the controller unit-testable
with no superglobals/`php://input`.

**Insert-then-enqueue: Option F.** Insert first (status `received`), then enqueue; only
enqueue when `inserted === true`. If `enqueue()` throws `QueueException`, the controller
returns **500** and leaves the `received` row. The provider redelivers on the non-2xx;
the redelivery dedupes to the existing row and re-attempts the enqueue. Residual risk: a
transient orphan `received` row if the provider never retries — accepted as debt; a
sweeper that re-enqueues stale `received` rows is a future option, not built in T1.5.

**Do NOT persist Invalid/Error requests in `webhook_events` (T1.5).** Only `valid()`
results carry a trustworthy `event_id`; an `Invalid`/`Error` result has **no** id
(`VerificationResult.php:55-63` — invalid/error force ids to null), so there is no
dedupe key to write and no NOT-NULL `event_id`/`event_type` to satisfy. Persisting
rejects would also let an attacker **flood** the audit table with unauthenticated rows
(a cheap DoS) and would force a nullable/synthetic key. So a rejected request gets its
status code (401/502/500) and **no row**. This is a deliberate, narrow reading of FR-9
("full event log") as "log every *authentic* event"; auditing *rejections* (counters /
a separate append-only table that cannot be used to flood the dedupe table) is deferred
and recorded as an open question, not silently dropped.

**Header normalisation** happens once, in the controller, before `verify()`:
`array_change_key_case($headers, CASE_LOWER)` — the verifiers require lower-cased keys
(`ProviderVerifier.php:29-34`) and the controller is the documented owner of that.

**Verifier construction is lazy, per-provider** (see ADR-relevant detail): the registry
constructs only the verifier for the provider actually being called, so a missing PayPal
secret cannot 500 GitHub ingestion (operational isolation).

## Consequences

- **Positive:**
  - AC-6 satisfied race-free: concurrent identical redeliveries produce exactly one row
    and exactly one enqueue; the loser sees `inserted=false`, returns 202, enqueues
    nothing.
  - The three-state outcome maps cleanly to retry-aware HTTP: 401 (don't retry — it's
    forged), 502 (retry — our outage), 500 (retry — our misconfig), 202 (done). Provider
    retries + dedupe = at-least-once with no double-processing.
  - Controller is pure/testable (value-object in, value-object out; no superglobals),
    so T1.6 just reads `php://input` + `getallheaders()`, calls `handle()`, and renders
    `IngestionResponse`.
  - The audit table only ever holds authenticated events with a real dedupe key; it
    cannot be flooded by forged traffic.
- **Negative / debt:**
  - **Orphan `received` rows** possible if Redis is down AND the provider never retries.
    Mitigated by provider redelivery; a reconciling sweeper is deferred (recorded).
  - **No audit of rejected/forged requests** in T1.5. If security review later wants
    visibility into rejected traffic, add counters or a separate, non-dedupe rejection
    log — explicitly not the `webhook_events` table. Open question.
  - The `ON DUPLICATE KEY UPDATE id = id` no-op is an idiom that MUST keep its comment so
    it is not "cleaned up" into a bug (it would then return 2 and the insert/duplicate
    signal would be lost).
  - PayPal's `Error` path (502) still costs two remote round-trips before we answer
    (ADR 0008) — unchanged here, just acknowledged in the mapping.
```
