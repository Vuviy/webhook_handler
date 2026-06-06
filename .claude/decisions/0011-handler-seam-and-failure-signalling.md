# 0011. Handler seam: interface shape and the transient/permanent failure boundary

- **Status:** Accepted
- **Date:** 2026-06-06

## Context

T2.1 opens Milestone 2 (Worker, retry, DLQ). It builds only the **handler seam**:
an interface, a registry mapping a provider tag → handler, and three stub handlers
(github / stripe / paypal) that do nothing meaningful yet. Per spec Non-goal §3
(`webhook-handler.spec.md:23`) acting on the business meaning of an event is out of
scope beyond a pluggable stub.

The worker (T2.2) will `BLPOP webhooks:queue`, read the job envelope
`{event_id, provider, attempt}` (`Queue.php:93`), re-read the event row from MySQL by
event id, and dispatch to the handler obtained from the registry. Retry/backoff (T2.3)
and the DLQ (T2.4) hinge on whether a handler failure is **transient** (retry) or
**permanent** (straight to DLQ) — the spec and skill both require this distinction
(`webhook-handler.spec.md:47`, `webhook-handler.spec.md:62`,
`queue-processing/SKILL.md:32`, `:52-58`).

So even though the handlers are stubs, **two decisions made now constrain T2.2–T2.4**
and are worth recording:

1. **What does `handle()` receive?** A thin worker and testable handlers depend on this.
2. **How does a handler signal transient vs permanent failure?** The interface must
   make the distinction expressible now without forcing T2.3 to redesign it later.

Constraints carried in from existing code:
- The job envelope is intentionally tiny; the heavy payload stays in MySQL and is
  referenced by id (`Queue.php:24-27`, `queue-processing/SKILL.md:19-20`).
- `EventRepository` today exposes only `insertReceived()` (`EventRepository.php:42`).
  There is **no read-by-id method yet** — that belongs to T2.2 (the worker), not T2.1.
- The established seam pattern is: a secret-free interface + a lazy `match`-based
  registry returning `null` for unknown tags (`ProviderVerifier.php`,
  `VerifierRegistry.php:36-44`).
- Conventions discourage one-implementation abstractions
  (`php-conventions/SKILL.md:14`); the handler interface is nonetheless justified
  because there are **three** implementations plus a registry seam — the same
  justification the verifier seam used.

## Options considered

### Decision 1 — what `handle()` receives

#### Option A — handler receives `(event_id, provider)` only; re-reads the DB itself
- Pros: Smallest signature; handler can read whatever columns it wants.
- Cons: Every handler needs a `PDO`/repository dependency and must re-implement the
  read; the worker would dispatch before the row is loaded, scattering DB access across
  three stubs. Makes handlers hard to unit-test (each needs a database). Pushes
  persistence into the leaf, the opposite of "thin worker, dumb handler".

#### Option B — handler receives a small read-only `WebhookEvent` value object
A value object carrying `provider`, `eventId`, `eventType`, raw `payload`, and the
current `attempt`. The **worker** reads the row once (T2.2) and constructs it; the
handler just consumes it.
- Pros: Handlers are pure functions of their input — trivially unit-testable with a
  hand-built event, no DB. The worker owns the single DB read (one place to get the
  row, one place to handle "row vanished"). Mirrors how `VerificationResult` carries
  already-extracted ids so the consumer stays provider-agnostic
  (`VerificationResult.php:7-17`). `attempt` is present so a future real handler can be
  idempotent / log the retry count without re-deriving it.
- Cons: Introduces one more value type now, before any handler uses its fields. The
  object's *construction* (the DB read + mapping) is deferred to T2.2, so T2.1 ships the
  type with the worker not yet filling it — a small "defined ahead of its producer" gap.

#### Option C — handler receives the decoded payload array
- Pros: Direct access to event data; no new type.
- Cons: A bare `array<string,mixed>` is untyped and provider-shaped — exactly what the
  verifier seam avoided by extracting ids into a typed result. It also forces a JSON
  decode decision into T2.1 and loses `provider`/`attempt`/`eventType` context the
  handler will want. Leaks payload shape into the seam.

### Decision 2 — how a handler signals transient vs permanent failure

#### Option D — defer entirely; `handle(): void`, no failure vocabulary yet
- Pros: Absolute minimum surface for T2.1.
- Cons: T2.3/T2.4's whole job is to branch on transient-vs-permanent
  (`queue-processing/SKILL.md:52-58`). If the interface says nothing, T2.3 must change
  the interface and retrofit all three stubs — i.e. T2.1 would have shipped a seam that
  is wrong for its only purpose. The spec already commits to the distinction
  (`webhook-handler.spec.md:47`), so deferring it leaves the seam knowingly incomplete.

#### Option E — return a result object (`HandlerResult::success()|transient()|permanent()`)
- Pros: Mirrors `VerificationResult`; failures are values, not control flow.
- Cons: Forces every handler — including a real one whose framework/library throws — to
  catch its own exceptions and translate them into the enum, on the happy path *and*
  every error path. Easy to forget; an uncaught exception then bypasses the result
  contract entirely and the worker must catch it anyway. So the worker ends up needing
  *both* a result switch and a catch-all, doubling the failure surface.

#### Option F — typed exceptions: `TransientHandlerException` / `PermanentHandlerException`, plus a catch-all default
`handle(): void`; success is "returned normally". A handler throws
`TransientHandlerException` for retryable faults (downstream timeout, 5xx, lock
contention) and `PermanentHandlerException` for un-retryable ones (malformed/poison
event, business rejection). The worker's contract (defined in T2.3) is:
`TransientHandlerException` → retry while `attempt < 3` else DLQ; `PermanentHandlerException`
→ DLQ immediately; **any other `Throwable` → treat as transient** (fail safe: an
unforeseen bug gets retried, not silently dropped) — matching the skill's
`catch Transient / catch Permanent` shape (`queue-processing/SKILL.md:52-58`).
- Pros: A handler that has nothing to say on success simply returns — no boilerplate on
  the happy path. Exceptions already propagate out of deep library code, so the worker
  catches at one place regardless of where the fault arose. The two typed exceptions
  make intent explicit and greppable, and live alongside the existing typed-exception
  family (`App\Exception\*`). T2.3 can build its branch purely from `catch` clauses
  without touching the interface or the stubs.
- Cons: Uses exceptions for an expected (not exceptional) outcome on the failure path —
  a known trade-off. Mitigated by the catch-all default so a handler that throws the
  "wrong" type is still handled conservatively.

## Decision

**Decision 1: Option B** — `handle()` receives a single read-only `WebhookEvent`
value object (`provider`, `eventId`, `eventType`, `payload` (raw bytes), `attempt`).
The worker (T2.2) reads the row once and builds it; handlers stay pure and
DB-free. This keeps the worker thin (one DB read, in one place) and the handlers
unit-testable, exactly as `VerificationResult` does for the controller.

**Decision 2: Option F** — failure is signalled with two typed exceptions,
`App\Exception\TransientHandlerException` and `App\Exception\PermanentHandlerException`.
`handle(): void`; normal return means success. These two classes are **defined in
T2.1** so the seam is complete and the stubs can reference them, but the worker's
*reaction* to them (retry vs DLQ, the catch-all-as-transient rule) is **owned by
T2.3/T2.4** — T2.1 commits to the vocabulary, not the policy. This is the smallest
commitment that keeps T2.3 from having to reshape the interface.

**Registry: lazy `match`, but for symmetry, not isolation.** We mirror
`VerifierRegistry` (`VerifierRegistry.php:36-44`): `get(string $provider): ?Handler`,
returning `null` for unknown tags (the worker decides what an unknown provider means —
likely DLQ, in T2.2). We explicitly note that the *operational-isolation* rationale
that justified the verifier registry's laziness (`VerifierRegistry.php:11-19`) — one
provider's missing secret must not break the others — **does not apply here**: stub
handlers take no secrets and no config and cannot throw on construction. We keep `match`
anyway for **consistency with the established seam** and because it costs nothing; we do
*not* claim isolation as the reason. A handler that later needs config can adopt a
`fromConfig()` factory at that point.

**What a stub handler does:** effectively a no-op that records its own idempotency
contract in a docblock. It does **not** write to the DB, update status, log payloads, or
touch Redis (all out of scope — those are T2.2+). It MAY note (in the docblock) that
under at-least-once delivery a real implementation must dedupe by the provider event id
(FR-11). Keeping the body empty avoids faking behaviour the worker doesn't yet invoke.

## Consequences

- **Positive:**
  - The seam is complete for its purpose: T2.2 can dispatch, and T2.3/T2.4 can branch on
    two exception types without altering the interface or the stubs.
  - Handlers are pure and DB-free → unit-testable with a hand-built `WebhookEvent`
    (relevant to T3.3). The single DB read lives in the worker, one place to also handle
    "row disappeared".
  - Consistent with the two seams already in the codebase (verifier interface + lazy
    registry; `VerificationResult` carrying extracted data), so there is one mental model
    for "interface + registry + value object", aiding the learning goal.
  - No new runtime dependency; two small exception classes join the existing
    `App\Exception\*` family.
- **Negative / debt:**
  - `WebhookEvent` ships in T2.1 before its producer (the worker's DB read) exists in
    T2.2 — a type defined slightly ahead of use. Accepted: it is the seam's input
    contract and T2.2 is the very next task.
  - Failure-as-exception on the handler's error path uses exceptions for an expected
    outcome. Mitigated by the worker's catch-all-as-transient rule (T2.3), which also
    guards against a handler throwing an untyped error.
  - The transient/permanent **policy** is only documented here and in the exception
    docblocks; it is not enforced until T2.3 writes the worker's catch logic. T2.1
    delivers vocabulary, not behaviour — by design.
