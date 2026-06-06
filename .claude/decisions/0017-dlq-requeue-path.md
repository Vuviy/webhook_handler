# 0017. DLQ re-queue / drain path (T3.2): an operator `bin/requeue-dlq.php` CLI, a `Queue` requeue-one mechanism, an `EventRepository` row-reset, and a `DlqRequeue` orchestrator — with Redis-pop-first / DB-reset / queue-push ordering

- **Status:** Accepted
- **Date:** 2026-06-06

## Context

T3.2 is the recovery half of the dead-letter story. T2.4 (ADR 0014) made the DLQ
**write-only**: a job that exhausts its 3 attempts or fails permanently is marked
`status='failed'` with `last_error` AND its envelope is RPUSH'd to `webhooks:dlq`
(`Queue::deadLetter()`, `Queue.php:226-233`; `RetryScheduler::deadLetter()`,
`RetryScheduler.php:101-105`). T3.1 (ADR 0016) made the DLQ **size visible** on the
dashboard (`Queue::deadLetterSize()`, `Queue.php:138-141`). Nothing yet **drains** the
list: once a downstream is fixed, an operator has no supported way to put a
dead-lettered event back on the live path. The code says so in four places —
`Queue.php:26`, `Queue.php:47-48`, `Queue.php:135-136`, `worker.php:31`, and ADR 0014's
scope fence all read "draining / re-queuing the DLQ is **T3.2**". This is that task.

The spec's **open question #4** (`webhook-handler.spec.md:101`) frames the choice as
"DLQ re-queue: manual (phpMyAdmin/CLI) **vs** a button in the dashboard". This ADR
resolves it.

### What "re-queue one DLQ entry" actually requires (two stores, two writes)

A dead-lettered job lives in **two** places and both must move back in lockstep:

1. **Redis.** The envelope `{event_id, provider, attempt}` sits in the `webhooks:dlq`
   list. To re-process it, it must come **off** `webhooks:dlq` and go **onto**
   `webhooks:queue` so the worker's `BLPOP` re-pops it (`Queue.php:115`).
2. **MySQL.** The `webhook_events` row is `status='failed'`, `attempts` at its terminal
   value (`MAX_ATTEMPTS=3` for exhaustion, or the throwing attempt for a permanent
   failure), `last_error` set. The worker claims a job with `markProcessing()`, which is
   guarded **`AND status='received'`** (`EventRepository.php:116-126`). A `failed` row can
   therefore **never** be re-claimed — re-pushing the envelope alone would make the worker
   pop a job, call `findForProcessing` (finds a `failed` row), call `markProcessing`
   (matches **0 rows**, returns false), and **silently skip it** (`worker.php:136-138`).
   So re-queue is **not** "push the envelope back"; it is **also** "reset the DB row to a
   state the guarded claim can win again" — i.e. back to `status='received'`.

### The attempt-budget problem (why a naive re-push is useless)

The envelope on the DLQ carries `attempt = 3` (the last attempt that ran, ADR 0014 D4).
If we re-pushed it unchanged onto `webhooks:queue`, the worker would pop it, the handler
would fail again, and `RetryScheduler::retryOrFail()` computes `next = attempt + 1 = 4 >
MAX_ATTEMPTS` (`RetryScheduler.php:70-76`) → **straight back to the DLQ on the very first
failure, with zero real retries**. A re-queue that does not **reset the attempt budget**
is a no-op dressed up as a recovery. So re-queue must rewrite the envelope's `attempt`
back to `1` (a fresh delivery) AND reset the DB `attempts` column to keep envelope and DB
coherent (the same control-value/audit-mirror coherence ADR 0013 D3 established).

### Constraints carried in from the existing code

- **Single ownership.** `Queue` is the sole owner of the three Redis key names and the
  sealed envelope schema, on every side (`encodeJob`/`decodeJob`, `Queue.php:239-282`).
  No caller may name `webhooks:dlq`/`webhooks:queue` or hand-build/parse an envelope.
  `EventRepository` is the sole owner of `webhook_events` SQL. A new "reset the row"
  query belongs on `EventRepository`; a new "move an envelope dlq→queue" belongs on
  `Queue`. This is the exact split ADRs 0005/0012/0013/0014/0016 all enforced.
- **Policy vs mechanism split** (ADR 0013 D4, ADR 0014 D1). `Queue` owns the raw Redis
  *mechanism*; the *policy/orchestration* (which DB write, in which order, then the move,
  and what to report) lives one level up — historically in `RetryScheduler`. The
  question for T3.2 is whether the orchestrator is `RetryScheduler` or a new small class.
- **The dashboard is pure JSON, framework-free, no HTML.** `index.php` *unconditionally*
  sets `Content-Type: application/json` (`index.php:69`) and the `dispatch()` contract is
  a `[status, body, allow]` triple with **no content-type slot** (`index.php:87-89`); ADR
  0016 D1 explicitly rejected server-rendered HTML for exactly this reason. There is **no**
  HTML page, **no** form, **no** POST-handling route, **no** CSRF token, **no** auth — the
  whole HTTP surface today is `GET /`, `GET /health`, and `POST /webhooks/{provider}`
  (`index.php:14-22`). A "button" is not a small addition: it is an HTML UI **plus** a new
  **state-changing POST endpoint** that pops Redis and rewrites DB rows.
- **The established operator-action pattern is a `bin/*.php` CLI.** `bin/migrate.php`
  (`migrate.php:1-47`) is the precedent: it `require`s `bootstrap.php`, builds its
  dependencies via the fail-fast factories, does the work, prints one line per unit to
  STDOUT, and `exit(0)`/`exit(1)` with a typed exception to STDERR. It lives in `bin/`
  **above** `public/`, so it is **never reachable over HTTP** (`migrate.php:16-17`) — an
  operator action, not a request. The worker (`worker.php`) follows the same boot shape.
- **At-least-once / crash-safety discipline.** The codebase already chose its ordering
  rules deliberately and they *differ by which stores are involved*:
  - `promoteDueRetries()` moves **Redis→Redis** with **RPUSH-then-ZREM** so a crash in the
    gap re-promotes rather than loses (`Queue.php:183-205`).
  - `RetryScheduler::deadLetter()` / `retryOrFail()` write **DB-then-Redis** so the audit
    row (the source of truth, NFR-4) is durable first and the tolerable partial state is a
    visible `failed` row, never an un-reclaimable stuck-`processing` orphan (`RetryScheduler.php:101-105`, ADR 0014 D2).
  T3.2 re-queue touches **both** stores and must pick its own ordering with the same lens:
  "which partial state is the recoverable, visible one?"
- **No silent drops.** Every `Queue` write throws loudly on a `false` Redis return
  (`enqueue`/`scheduleRetry`/`deadLetter`). The re-queue mechanism must keep that
  invariant: a job half-moved must surface, not vanish.
- **Single worker** (spec Non-goal `webhook-handler.spec.md:26`). The operator running
  the CLI and the worker can run concurrently, but there is exactly **one** worker
  consumer and (assumed, see D-defaults) **one** operator running this CLI at a time.

Four genuine choices arise: **(D1)** CLI vs dashboard button; **(D2)** drain granularity;
**(D3)** the re-queue mechanics & cross-store ordering; **(D4)** where the new code lives
and the attempt-reset semantics.

## Options considered

### Decision 1 — CLI vs a dashboard button (resolves spec open question #4)

#### Option A — an operator `bin/requeue-dlq.php` CLI (mirrors `bin/migrate.php`)
- A new `bin/requeue-dlq.php` that boots like `migrate.php`/`worker.php`, builds `Queue` +
  `EventRepository` via the fail-fast factories, and drives a `DlqRequeue` orchestrator.
  Prints one line per re-queued event to STDOUT, a summary, and `exit(0)`/`exit(1)`.
- Pros: **Reuses the codebase's one established operator-action pattern verbatim** —
  `bin/migrate.php` is the template, down to the boot, the STDOUT-per-unit, the exit
  codes, and the "lives above `public/`, never HTTP-reachable" property (`migrate.php`).
  A DLQ drain **is** an operator action (you fixed the downstream, now you replay), exactly
  like running migrations — not something a webhook provider or anonymous browser should
  trigger. **Zero new HTTP surface**: no POST route, no HTML, no CSRF, no auth question —
  none of which exist today and all of which a state-changing button would *require* before
  it could be safe (popping Redis + rewriting rows from an unauthenticated endpoint on the
  local-only dashboard is a footgun). Smallest, safest delta; it composes cleanly with the
  policy/mechanism split (the CLI is a thin entry point over a `Queue` mechanism + an
  orchestrator, just as `worker.php` is thin over `RetryScheduler`/`Queue`). Right size for
  a junior-learning, framework-free project and for an "S" task.
- Cons: not a one-click button for a non-technical operator; you must `docker compose exec`.
  Accepted: the audience here is the developer/operator, the action is infrequent and
  deliberate, and a CLI is the *honest* shape for a destructive, privileged operation. A
  dashboard button can be layered on later (ADR 0016 already anticipates a static front)
  **on top of** the same orchestrator — this choice does not foreclose it.

#### Option B — a dashboard "Re-queue" button (HTML + a state-changing POST endpoint)
- Add an HTML page with a button (or per-row buttons) that POSTs to a new
  `POST /dashboard/requeue` route which pops the DLQ and resets rows.
- Pros: one-click for a human; visible right next to the DLQ size that T3.1 shows.
- Cons: **Large, cross-cutting delta against every grain of the current design.** It forces
  (1) an **HTML rendering path** the front controller deliberately does not have — ADR 0016
  D1 rejected server-rendered HTML because `index.php` hardcodes `application/json`
  (`index.php:69`) and the `dispatch()` triple has no content-type slot; (2) a **new
  state-changing POST route**, the first non-webhook mutating endpoint in the app; (3)
  **CSRF protection** (a browser form that pops Redis and rewrites DB rows is a classic CSRF
  target) — and there is no session/token machinery; (4) the **auth** question the spec
  Non-goals (`:22-26`) and ADR 0016 explicitly deferred ("internal view behind the local
  Docker network") now becomes load-bearing, because a *mutation* behind no auth is far
  worse than a *read*. That is a milestone's worth of HTTP/security work for an "S" task
  whose job is "drain the DLQ". Rejected for T3.2; explicitly left as a possible later
  feature **over** the Option-A orchestrator.

#### Option C — "manual via phpMyAdmin / raw Redis" (the do-nothing option named in the question)
- Tell the operator to `LPOP webhooks:dlq` by hand and `UPDATE webhook_events SET
  status='received', attempts=0` in phpMyAdmin.
- Pros: zero code.
- Cons: **Unsafe and unrepeatable.** It requires the operator to know the sealed Redis key
  name and envelope schema (breaking the single-ownership `Queue` enforces), to get the
  **two-store ordering right by hand** (the exact crash-safety the code reasons about in
  D3), and to reset the attempt budget correctly — every time, with no record. One fat-
  fingered `UPDATE` corrupts the audit log. It is the *absence* of T3.2, not a design.
  Rejected: T3.2 exists precisely to make this a supported, single, correct operation.

### Decision 2 — drain granularity (all / one / N)

#### Option D — drain ALL by default, with an optional single-event argument
- `php bin/requeue-dlq.php` re-queues **every** entry currently on `webhooks:dlq`;
  `php bin/requeue-dlq.php <event_id>` (optional, see D-defaults) re-queues just that one.
- Pros: the common real operation is "the downstream is healthy again, replay everything
  that piled up" — drain-all is the 80% case and the natural default. A single-event mode
  covers the "replay just this one to test the fix" case without a separate tool. Matches
  `migrate.php`'s "apply everything pending" default shape. Bounded and simple: the loop
  reads the list length once and moves that many (it does **not** chase entries that arrive
  *after* it started, avoiding an unbounded live-lock if the worker were somehow re-DLQ-ing).
- Cons: drain-all could re-queue a poison message that will just fail straight back to the
  DLQ (3 attempts then back). Accepted and bounded: with the attempt budget reset (D4) it
  gets a fair 3 tries; if it fails again it returns to the DLQ visibly (the dashboard size
  ticks back up) — no worse than before, and the single-event mode lets the operator test
  one first. A "max N" flag is unnecessary surface for an "S" task (drain-all + by-id covers
  the real needs); note it as a trivial later add, do not build it.

#### Option E — single-event only (operator must name each id)
- Pros: maximally cautious; never mass-replays a poison flood.
- Cons: makes the common "replay the backlog" case O(n) manual invocations; the operator
  must enumerate ids from the dashboard/DB by hand. Too tedious for the primary use case.
  Rejected as the *default*; preserved as the *optional* mode in D.

#### Option F — fixed batch of N per run
- Pros: caps blast radius per invocation.
- Cons: arbitrary N, and the operator must re-run to finish a real backlog. Drain-all
  already reads a fixed snapshot of the list length, so it is naturally bounded per run
  without a magic N. Rejected; a `--limit` flag is a later nicety, not T3.2.

### Decision 3 — re-queue mechanics & cross-store ordering (the crash-safety core)

For each entry we must do three things: **(a)** remove the envelope from `webhooks:dlq`;
**(b)** reset the `webhook_events` row to `status='received'` with a fresh attempt budget;
**(c)** put a fresh-attempt envelope on `webhooks:queue`. Three writes (one Redis pop, one
DB update, one Redis push), no cross-store transaction. The ordering must pick the
recoverable partial state if the CLI dies mid-entry.

The decisive asymmetry (same lens as ADR 0014 D2): the **DB row is the source of truth**,
and the worker's guarded `markProcessing` means a job is only ever *processed* if its row
is `received`. The DLQ list, by contrast, is operator-facing storage. So:

- The **dangerous** partial state is "envelope is on `webhooks:queue` but the row is still
  `failed`": the worker pops it, `markProcessing` matches 0 rows, and it is **silently
  skipped** (`worker.php:136-138`) **and now also gone from the DLQ** — the job has
  vanished from every actionable surface. That must never be the crash residue.
- The **recoverable** partial state is "row reset to `received`, but the fresh envelope not
  yet on `webhooks:queue`": the event is visible (dashboard counts it under `received`), and
  it is harmless to re-run the CLI — it will find the row already `received` and just (re)push
  the envelope. A `received` row with no live envelope is the same benign state a
  retry-promotion crash already produces, and is reconcilable.

#### Option G — per entry: LPOP `webhooks:dlq` → reset DB row → RPUSH `webhooks:queue`, one at a time, with reset-before-push
- For each of the N entries (N = list length read once at start):
  1. `LPOP webhooks:dlq` to **take** one envelope off the dead-letter list (atomic single-
     element pop; the operator now "holds" it).
  2. **decode** it to a `Job` (reuse the sealed `decodeJob`), and **reset the DB row** for
     `(provider, event_id)`: `status='received'`, `attempts=0`, via a new
     `EventRepository::requeueFromDlq()` — **DB write BEFORE the queue push**.
  3. **RPUSH** a **fresh-attempt** envelope (`attempt=1`, D4) onto `webhooks:queue`.
- Why **LPOP first** (Redis-pop before the DB write), unlike `deadLetter`'s DB-first: here
  the very first step is to *consume* the DLQ entry so it cannot be re-queued twice by a
  re-run, and the envelope it yields is the *input* to the other two writes — we cannot reset
  the right row or push the right envelope without first having popped it. The crash residue
  of "LPOP succeeded, then died before the DB reset" is **an envelope held nowhere** — i.e.
  one DLQ entry lost from the list while its row stays `failed` (visible on the dashboard).
  That is the *same tolerable class* as ADR 0014's "failed row, no DLQ entry": the audit row
  still truthfully says `failed`, the operator sees it, and it can be re-driven (worst case
  by id once the row is known). Crucially it is **not** the dangerous "live envelope, failed
  row, silently skipped" state — that one is impossible here because the push is **last**.
- Why **reset DB before RPUSH** (step 2 before step 3): identical reasoning to
  `retryOrFail`/`deadLetter` (ADR 0013/0014, "DB write FIRST"). If the RPUSH fails/crashes
  after the reset, the row is at the recoverable `received` (visible, re-pushable on re-run),
  never an envelope-on-queue-pointing-at-a-failed-row orphan.
- Why **one at a time, LPOP not LRANGE**: a single `LPOP` per entry means a crash leaves the
  *rest* of the list intact on `webhooks:dlq` — re-running the CLI resumes the drain. Reading
  the whole list with `LRANGE` and then deleting would reintroduce a read-then-delete window
  and risk double-processing the tail; `LPOP`-per-entry is the natural, resumable unit (and
  mirrors how `promoteDueRetries` processes due members one move at a time, `Queue.php:193-202`).
- Pros: every crash residue is the **recoverable/visible** class, never the silent-skip
  orphan; resumable (re-run finishes the drain); reuses the sealed `decodeJob`/`encodeJob`
  so the envelope schema and key names stay owned by `Queue`; no `LRANGE`/`DEL` race; loud on
  any failed Redis write (keeps the no-silent-drop invariant). The single-worker +
  single-operator assumption makes the LPOP→push window safe (the only consumer of
  `webhooks:queue` is the worker, and the guarded claim neutralises any stray duplicate
  anyway, exactly as ADR 0013 D2 reasoned).
- Cons: not atomic across the two stores (no XA). Accepted with eyes open: the chosen
  ordering makes the *only* possible partial state a visible, reconcilable one, which is the
  same trade every other write-path in this codebase already makes. Per-entry round-trips
  (LPOP + UPDATE + RPUSH) are fine at the DLQ's scale (a human-drained backlog, not a hot
  path).

#### Option H — LRANGE the whole list, reset all rows, RPUSH all, then DEL the DLQ key
- Read every envelope with `LRANGE 0 -1`, process them, then `DEL webhooks:dlq`.
- Cons: the `LRANGE … DEL` pair is a read-then-delete window — if the worker or a second
  operator interacts, or the CLITES dies mid-batch, entries can be **re-processed or lost**
  in bulk rather than one resumable unit at a time. A bulk `DEL` also throws away entries
  that arrived *after* the `LRANGE` snapshot (e.g. a fresh DLQ push during the drain) —
  silent loss, breaking the no-drop invariant. Rejected in favour of G's resumable,
  per-entry `LPOP`.

#### Option I — RPUSH to `webhooks:queue` first, then DB reset, then remove from DLQ
- Cons: pushing the live envelope **before** the row is `received` recreates the dangerous
  state — the worker can pop it and silently skip it (guarded claim), and if the crash lands
  before the DB reset the job is on the live queue pointing at a `failed` row. This is the
  exact inversion ADR 0014 D2 rejected. Rejected.

### Decision 4 — where the new code lives, and the attempt-reset semantics

#### Option J — a `Queue` requeue mechanism + an `EventRepository` row-reset + a small `DlqRequeue` orchestrator + the `bin/` CLI
- **Mechanism (Queue, owns Redis keys + envelope).** Add
  `Queue::requeueOneFromDeadLetter(): ?Job` — `LPOP webhooks:dlq`; if the list is empty
  return `null`; otherwise `decodeJob()` the popped envelope and return the typed `Job` so
  the orchestrator knows *which* event to reset, **without** the key name or envelope schema
  ever leaving `Queue`. The matching push back onto the main queue **reuses the existing
  `Queue::enqueue($eventId, $provider, 1)`** (`Queue.php:85`) — it already RPUSHes a
  **fresh attempt=1** envelope onto `webhooks:queue` and already throws on failure, so no new
  push method is needed (the re-queue is literally "enqueue it again as a first delivery").
  This keeps the LPOP and the decode sealed in `Queue`, mirroring how `consume()` pops+decodes
  and `promoteDueRetries()` moves entries — all Redis-key/envelope knowledge stays in one class.
- **Persistence (EventRepository, owns the SQL).** Add
  `EventRepository::requeueFromDlq(string $provider, string $eventId): bool` —
  `UPDATE webhook_events SET status='received', attempts=0, last_error=NULL WHERE provider=:p
  AND event_id=:e AND status='failed'`. The `AND status='failed'` guard makes it **idempotent
  and safe**: it only ever re-arms a row that is genuinely dead-lettered, returns true iff it
  reset exactly that row, and false if the row is absent or not `failed` (so a re-run, or a
  DLQ envelope whose row was meanwhile re-driven, is a harmless no-op, not a corruption).
  Resetting `attempts=0` (and clearing `last_error`) gives the replay a **full fresh 3-attempt
  budget** — the fix to the attempt-budget problem (Context) — and keeps the DB audit mirror
  coherent with the fresh `attempt=1` envelope (ADR 0013 D3 coherence). *(D-default below
  records why reset-to-0 over keep-the-count.)*
- **Orchestration/policy.** Add a small **new** `final class DlqRequeue` (in `App\Queue`)
  with one method, e.g. `drainAll(): DlqRequeueReport` (and/or `requeueOne(string $eventId)`),
  that holds the **ordering policy** from D3 — LPOP → `requeueFromDlq` (DB) → `enqueue`
  (re-push), looping until `requeueOneFromDeadLetter()` returns `null` (list drained) or the
  initial count is exhausted — and tallies what happened (re-queued vs skipped-because-row-
  not-failed vs skipped-because-row-missing) for the CLI to print. **A new class, not a
  method on `RetryScheduler`:** `RetryScheduler` owns the *failure-direction* policy
  (received→retry→DLQ); this is the *recovery-direction* policy (DLQ→received). Bolting a
  drain onto `RetryScheduler` would stretch its name past breaking and mix the
  forward/backward flows in one class. A dedicated, tiny `DlqRequeue` keeps each policy class
  single-purpose — the same instinct that gave T3.1 a `DashboardController` rather than piling
  read methods onto an existing class.
- **Entry point.** Add `bin/requeue-dlq.php`, a near-clone of `bin/migrate.php`: `require
  bootstrap.php`; build `Queue::fromConfig(...)` + `EventRepository(Connection::fromConfig(...))`
  (fail-fast); construct `DlqRequeue`; run drain-all (or by-id if an arg is given, D2/D-default);
  print one line per re-queued event + a summary; `exit(0)`, or a typed `QueueException`/
  `DatabaseException` to STDERR + `exit(1)`. Lives above `public/`, never HTTP-reachable.
- Pros: every new piece lands on the class that **already owns that concern** — Redis
  key+envelope on `Queue`, SQL on `EventRepository`, an entry point in `bin/` — so the
  single-ownership and policy/mechanism splits the prior ADRs built are preserved exactly.
  Re-uses `enqueue()` for the push (no redundant method) and `decodeJob()` for the decode (no
  leaked schema). The two guards (`requeueFromDlq`'s `AND status='failed'` and the worker's
  `markProcessing` `AND status='received'`) compose to make the whole path **idempotent and
  re-runnable** under at-least-once. Small, testable units; the CLI stays thin like
  `migrate.php`/`worker.php`.
- Cons: four new artifacts (one Queue method, one repo method, one tiny orchestrator class +
  a small report value, one CLI). For an "S" task that is a few methods, all small and
  additive — comparable to T3.1's footprint. Accepted.

#### Option K — put it all in the CLI script (no Queue/repo methods, raw Redis + SQL in `bin/`)
- Cons: the CLI would name `webhooks:dlq`/`webhooks:queue` and hand-build/parse envelopes,
  **breaking the single-ownership `Queue` has enforced since ADR 0005**, and embed raw SQL
  outside `EventRepository`. It would also duplicate the ordering policy inline with no reuse
  or unit test. Rejected: entry points stay thin; owners stay single.

#### Option L — add the drain as methods on `RetryScheduler` (reuse the existing orchestrator)
- Cons: mixes the recovery direction into the class whose entire vocabulary is the failure
  direction (backoff, exhaustion, dead-letter); stretches its name and couples two opposite
  flows. A dedicated `DlqRequeue` is clearer and just as small. Rejected (see J's rationale).

## Decision

- **D1: Option A — an operator `bin/requeue-dlq.php` CLI**, mirroring `bin/migrate.php`.
  This **resolves spec open question #4 in favour of a CLI**, not a dashboard button. A DLQ
  drain is a privileged, infrequent, destructive operator action — the same category as
  migrations — and the CLI pattern already exists, adds **zero HTTP/CSRF/auth surface**, and
  stays above `public/` (never request-reachable). A button (Option B) would force an HTML
  path the front controller deliberately lacks (ADR 0016 D1) plus a state-changing POST with
  CSRF/auth concerns — a milestone of work for an "S" task — and can be layered later **over**
  the same orchestrator. Raw phpMyAdmin/Redis (Option C) is the unsafe non-design T3.2 exists
  to replace.
- **D2: Option D — drain ALL by default, with an optional single-`event_id` argument.**
  `php bin/requeue-dlq.php` replays the whole current DLQ (the "downstream is healthy, replay
  the backlog" 80% case); `php bin/requeue-dlq.php <event_id>` replays just one (test-the-fix
  case). Bounded by the list length read at start; no `--limit`/batch flag in T3.2 (noted as a
  trivial later add).
- **D3: Option G — per entry, `LPOP webhooks:dlq` → reset DB row (`status='received'`) →
  `RPUSH webhooks:queue` (fresh attempt), one resumable entry at a time.** **Pop-first** so the
  entry is consumed once and re-runs resume rather than double-queue; **DB-reset-before-push**
  (same rule as `retryOrFail`/`deadLetter`) so the only possible crash residue is the
  **recoverable/visible** state (a `received` row, or at worst a `failed` row whose envelope was
  popped — both visible on the dashboard), **never** the dangerous "live envelope pointing at a
  `failed` row that the worker silently skips". `LPOP`-per-entry (not `LRANGE`+`DEL`) keeps the
  drain resumable and avoids the bulk read-then-delete race. Every Redis write stays loud on
  failure (no silent drop).
- **D4: Option J — a `Queue` mechanism + an `EventRepository` reset + a small `DlqRequeue`
  orchestrator + the `bin/` CLI**, each on the class that owns the concern:
  - `Queue::requeueOneFromDeadLetter(): ?Job` — `LPOP webhooks:dlq`, `decodeJob()`, return the
    typed `Job` or `null` when empty (key + envelope stay sealed in `Queue`).
  - **Re-push reuses `Queue::enqueue($eventId, $provider, 1)`** — it already RPUSHes a fresh
    `attempt=1` envelope onto `webhooks:queue` and throws on failure; no new push method.
  - `EventRepository::requeueFromDlq(string $provider, string $eventId): bool` —
    `UPDATE … SET status='received', attempts=0, last_error=NULL WHERE provider=:p AND
    event_id=:e AND status='failed'`; returns true iff exactly that dead-lettered row was reset
    (idempotent; a no-op on a re-run or an already-re-driven row).
  - `DlqRequeue` (new `final` class in `App\Queue`) — holds the D3 ordering loop and tallies a
    small report; **not** folded into `RetryScheduler` (opposite flow direction).
  - `bin/requeue-dlq.php` — thin entry point cloned from `bin/migrate.php` (bootstrap, fail-fast
    factories, STDOUT-per-event + summary, `exit(0)`/`exit(1)` with a typed exception to STDERR).

### Resolved open questions (defaults the architect set, per SOP)

- **Attempt budget on re-queue → reset to a FULL fresh budget** (`attempts=0` in DB,
  `attempt=1` on the envelope), **not** "keep the count" (which would re-DLQ on the first new
  failure — useless, see Context) and **not** "give one more attempt". Rationale: a re-queue
  happens *because the operator believes the cause is fixed*; the event deserves a clean
  3-attempt run, and a fresh budget keeps the envelope/DB coherent (ADR 0013 D3). `last_error`
  is **cleared** to `NULL` on reset so the dashboard's recent-failures view (`recentFailures`,
  reads `status='failed'`, `EventRepository.php:251-279`) no longer shows a now-replayed event
  as a current failure; the prior error is already historical and need not persist on a row
  that is being re-driven.
- **CLI invocation shape:** `php bin/requeue-dlq.php` = drain all; `php bin/requeue-dlq.php
  <event_id>` = re-queue that one event (single-event mode pops/scans for that id; if the DLQ
  is large this is acceptable at human scale, but the simplest correct implementation for the
  by-id case is to reset the row by id and re-`enqueue` it, then leave its stale DLQ envelope to
  be reaped by a later drain-all — OR, simpler still for T3.2, support **only drain-all** and add
  by-id later. **Default: ship drain-all in T3.2**; by-id is the documented next nicety, since the
  primary use case is replaying the backlog.) Usage banner printed like `migrate.php`'s docblock.
- **Edge cases & what the CLI reports:**
  - **Empty DLQ** → `requeueOneFromDeadLetter()` returns `null` on the first call; print
    "Dead-letter queue is empty — nothing to re-queue." and `exit(0)` (the `migrate.php`
    "up to date" analogue).
  - **DLQ envelope whose row is missing or not `failed`** → `requeueFromDlq()` returns false
    (guarded `AND status='failed'`); the orchestrator **does NOT** re-push that envelope (no
    point re-queueing an event with no claimable row), counts it as `skipped`, and the CLI
    prints a `skipped: <event_id> (no failed row)` line. The envelope is already LPOP'd (gone
    from the DLQ), which is correct — a dangling envelope for a non-existent/processed row
    should not linger.
  - **Decode failure on a popped envelope** (corrupt DLQ entry) → `requeueOneFromDeadLetter()`
    surfaces a `QueueException::decodeFailed()` (reusing the sealed decode, `Queue.php:263-279`);
    the CLI catches it at the loop level, logs/prints it as a skipped corrupt entry, and
    continues the drain rather than aborting the whole run on one poison envelope.
  - **Per-event success line** → `re-queued: <provider> <event_id>`; **summary** →
    `Done — N re-queued, M skipped.`; `exit(0)` on a completed drain, `exit(1)` only on an
    infra fault (Redis/DB down) surfaced as a typed exception, matching `migrate.php`.
- **Concurrency / auth:** assume **one operator runs the CLI at a time** and it may run
  **alongside the single worker** — safe because (a) `LPOP` is atomic so two concurrent
  drains could never pop the same envelope twice, and (b) the worker's guarded `markProcessing`
  + the repo's guarded `requeueFromDlq` neutralise any stray duplicate, exactly as ADR 0013 D2
  reasoned for promotion. No locking is added (YAGNI at single-worker scale; noted for a
  multi-operator future). No auth: the CLI requires shell/`docker exec` access, which **is** the
  authorization boundary — strictly stronger than the unauthenticated HTTP button Option B would
  have needed to defend.

### Component / method list (structure only — no feature code)

```
NEW  Queue::requeueOneFromDeadLetter(): ?Job
     // LPOP webhooks:dlq; null if empty; else decodeJob() -> typed Job. Key+envelope stay in Queue.

REUSE Queue::enqueue($eventId, $provider, 1)
     // the re-push onto webhooks:queue with a FRESH attempt=1 envelope (already exists, throws on fail).

NEW  EventRepository::requeueFromDlq(string $provider, string $eventId): bool
     // UPDATE … SET status='received', attempts=0, last_error=NULL
     //   WHERE provider=:p AND event_id=:e AND status='failed';  returns true iff one row reset.

NEW  final class App\Queue\DlqRequeue
       __construct(Queue $queue, EventRepository $events)
       drainAll(): DlqRequeueReport      // loop: requeueOneFromDeadLetter() -> requeueFromDlq() (DB) -> enqueue() (push)
       // (optional later) requeueOne(string $eventId): bool
     // holds the D3 ordering (pop -> reset -> push) and the re-queued/skipped tally. Recovery-direction
     // policy — deliberately NOT on RetryScheduler (failure-direction).

NEW  (tiny) App\Queue\DlqRequeueReport  // value object: counts re-queued / skipped, for the CLI to print.

NEW  bin/requeue-dlq.php
     // clone of bin/migrate.php: bootstrap -> fail-fast factories -> DlqRequeue -> drainAll()
     //   -> STDOUT per event + summary -> exit(0); typed exception to STDERR -> exit(1). Above public/.
```

`Queue::deadLetter()`, `deadLetterSize()`, `RetryScheduler`, `worker.php`, the dashboard, and
the front controller are **UNCHANGED**. The change is purely additive: one new `Queue` read/pop
method, one new `EventRepository` reset method, one small orchestrator (+ a report value), and
one `bin/` entry point. No new HTTP route, no HTML, no content-type change, no auth/CSRF surface.

## Consequences

- **Positive:**
  - **FR-8's recovery half is delivered** and **spec open question #4 is resolved** (CLI, not
    button): a dead-lettered event can be put back on the live path with one supported,
    repeatable command, after the operator fixes the downstream.
  - **Zero new HTTP/security surface.** No POST route, no HTML, no CSRF, no auth question — the
    shell/`docker exec` boundary is the authorization, strictly stronger than an unauthenticated
    dashboard button. The front controller and its JSON/Throwable contracts are untouched.
  - **Single ownership upheld:** the `webhooks:dlq`/`webhooks:queue` keys and the envelope stay
    sealed in `Queue` (`requeueOneFromDeadLetter` pops+decodes, `enqueue` re-pushes); all SQL
    stays in `EventRepository`. Nothing leaks into the CLI (Option K rejected).
  - **Policy/mechanism split preserved:** `Queue` = Redis mechanism, `DlqRequeue` = recovery
    orchestration (kept off `RetryScheduler`, whose vocabulary is the opposite direction).
  - **Crash-safe by construction:** pop-first + DB-reset-before-push makes every partial state
    the visible/recoverable kind (a `received` row, or a popped-but-still-`failed` row on the
    dashboard) and makes the only dangerous state — a live envelope over a `failed` row the
    worker silently skips — **impossible**. The run is **resumable** (re-run finishes a
    crashed drain) and **idempotent** (both guards absorb stray duplicates).
  - **The attempt budget is genuinely reset** (`attempts=0` / `attempt=1`), so a re-queued event
    gets a real fresh 3-attempt run instead of bouncing straight back to the DLQ — the
    correctness fix the naive re-push would have missed.
  - Small, additive, testable units; the CLI is as thin as `migrate.php`.

- **Negative / debt:**
  - **No cross-store transaction** across the LPOP / DB-reset / RPUSH triple — mitigated by the
    D3 ordering (only recoverable/visible partial states) and the re-run/idempotency guards, the
    same trade every write-path in this codebase already accepts. Documented at the orchestrator.
  - **A genuine poison message re-DLQs.** Drain-all gives it a fresh 3-attempt run; if it still
    fails it returns to the DLQ (dashboard size ticks back up) — no worse than before, and the
    by-id mode (default-deferred) lets the operator test one first. No automatic poison-quarantine
    beyond the DLQ itself (out of scope for "S").
  - **Drain-all is the only mode shipped in T3.2** by default; single-`event_id` re-queue and a
    `--limit` flag are documented next niceties, not built here (scope "S").
  - **No locking / multi-operator safety.** Correct for the single-worker, single-operator
    assumption (LPOP atomicity + the two guards cover stray duplicates); a multi-operator future
    would add a lock, a localized change. Noted at the method.
  - **A skipped envelope (no `failed` row) is dropped from the DLQ**, not re-queued — intentional
    (nothing claimable to re-drive) and reported, but it does mean the dangling entry is consumed
    rather than preserved for inspection. Acceptable: such an entry is already an inconsistency
    (DLQ envelope with no matching dead-lettered row) and the CLI surfaces it in the summary.
  - **`last_error` is cleared on re-queue**, so the *reason* the event originally failed is no
    longer on the row after a successful replay. The audit of the failure window still exists in
    logs; if retaining the prior error on the row is later wanted, that is a one-line change
    (drop the `last_error = NULL`). Flagged, not solved, to keep the replayed row clean for the
    dashboard.
```
