# 0006. ProviderVerifier interface: return shape and input contract

- **Status:** Accepted
- **Date:** 2026-06-05

## Context

T1.1 defines the single seam every provider verifier (T1.2 GitHub, T1.3 Stripe,
T1.4 PayPal) and the `IngestionController` (T1.5) depend on. We are designing only
the contract, not an implementation, so the shape has to be right for *three*
different schemes at once without leaking any one provider's mechanics into the
interface.

Forces (from `webhook-handler.spec.md` and the migration):

- The controller must distinguish **three distinct outcomes**, not two:
  1. signature **valid** → insert `received`, enqueue, `202`;
  2. signature **invalid/missing** → `401`, do **not** enqueue (FR-5);
  3. verification could not be *performed* because of a **transport error** —
     only PayPal (FR-3) calls a remote `verify-webhook-signature` API, which can
     time out or 5xx. That is operationally different from "the signature is a
     forgery": it is not the caller's fault and should not be cached as a `401`.
- `webhook_events` (migration `0001`) has `event_type NOT NULL` and a
  `UNIQUE (provider, event_id)` that is the dedupe backbone (FR-11). Both columns
  must be populated at ingestion time, *before* the worker ever runs. The
  `event_id` and `event_type` are parsed out of the body/headers — work that is
  provider-specific and that the verifier already has to read the body for.
- Headers differ per provider (GitHub `X-Hub-Signature-256`; Stripe
  `Stripe-Signature`; PayPal several `Paypal-*` headers), so the interface must be
  header-shape-agnostic.
- The per-provider secret already lives in `App\Config\ProviderSecrets` and is
  optional at boot.
- House style (`Queue.php`, `QueueException.php`): small `final` classes, typed
  factory exceptions, rich "why" doc-comments, no abstraction with a single
  trivial purpose.

The two genuinely architectural choices are **(A) how a verify() call signals its
outcome** and **(B) the input shape**. The rest (secret injection, provider name)
follow once those are fixed.

## Options considered — (A) outcome signaling

### Option A1 — `bool verify(...)`
- Pros: simplest possible contract; familiar.
- Cons: collapses the three outcomes into two. A `false` cannot tell the
  controller "forged" from "PayPal API unreachable", so the controller would
  return `401` for an outage — wrong (FR-5 vs NFR-2 "transient vs permanent").
  Also gives the controller nothing for `event_id`/`event_type`, forcing a second,
  duplicate parse of the same body outside the verifier — and that parse differs
  per provider, recreating provider branching in the controller.

### Option A2 — `void verify(...)` that throws `SignatureException` on failure
- Pros: a "valid path returns, invalid path throws" contract is clean; typed
  exception matches house style.
- Cons: uses exceptions for an expected, routine outcome (a bad signature on a
  public endpoint is normal traffic, not exceptional). Still has to return the
  extracted `event_id`/`event_type` *somehow* on the success path, so we end up
  needing a result object anyway — exception-for-invalid plus object-for-valid is
  two mechanisms for one decision. Distinguishing transport-error from invalid
  then means *two* exception types the controller must catch and branch on, which
  is the same branching as a result enum, just less greppable.

### Option A3 — return a `VerificationResult` value object (chosen)
- A single immutable result carrying: an outcome enum
  (`Valid` / `Invalid` / `Error`), and — when `Valid` — the extracted
  `eventId` and `eventType`; when not valid, a short non-sensitive `reason`.
- Pros: models exactly the three outcomes the controller needs; carries the
  `event_id`/`event_type` the controller must persist, so the body is parsed once,
  inside the provider that already understands it; `Invalid` (a forged/missing
  signature) is an ordinary return value, not an exception, which fits "validate
  at the boundary, expect bad input" (SKILL.md). Transport faults still map to the
  `Error` outcome rather than a `401`.
- Cons: introduces two small supporting types (the result + an outcome enum) in
  T1.1 instead of one bare method. Justified: the contract is *defined by* those
  outcomes, so they are in scope, not gold-plating.

Reserve a typed `VerificationException` (factory style, mirrors `QueueException`)
strictly for **programmer/config errors** that are not a normal request outcome —
e.g. the provider secret is missing/empty (`ProviderSecrets` returns `null`). A
missing secret is a deployment fault, must surface loudly (the controller turns it
into `500`, never `401`), and must never be confused with a forged signature.
Routine "bad signature" and "remote verify failed" stay as `Invalid` / `Error`
results.

## Options considered — (B) input shape

### Option B1 — `verify(string $rawBody, array $headers)`
- Pros: trivially provider-agnostic; raw bytes passed explicitly (NFR-1); no new
  type. Each verifier reads the headers it needs (case-insensitively).
- Cons: `array $headers` is an untyped bag; header-name casing/normalisation is
  each verifier's problem. Acceptable — the controller normalises once.

### Option B2 — a `WebhookRequest` DTO (rawBody + headers + provider)
- Pros: a named seam; room to grow (e.g. source IP for PayPal allow-lists later).
- Cons: a second new type in an interface-only subtask whose only field beyond
  B1 is the provider name, which the registry already knows. Premature; violates
  "avoid abstractions that have only one implementation" until a real need appears.

### Option B3 — individual named header params
- Pros: explicit per provider.
- Cons: impossible to keep provider-agnostic — GitHub needs one header, PayPal
  needs five. Rejected outright.

## Decision

- **(A) Option A3** — `verify()` returns an immutable `VerificationResult` value
  object with a three-state outcome (`Valid`/`Invalid`/`Error`) and, on `Valid`,
  the extracted `eventId` + `eventType`. A separate `VerificationException` covers
  only misconfiguration (missing secret), not routine bad signatures.
- **(B) Option B1** — `verify(string $rawBody, array $headers): VerificationResult`.
  Raw bytes in, normalised header map in, no DTO yet.
- **Secret supply:** injected into each concrete verifier's constructor from
  `ProviderSecrets` (T1.2+). The interface stays secret-free, so it never sees an
  env value and nothing secret can be logged through it.
- **Provider identity:** add `provider(): string` to the interface so a registry
  can map `/webhooks/{provider}` → the matching verifier and so the result/row
  carry a consistent provider tag. The string-→-verifier registry itself is T1.5,
  not T1.1.

In-scope for T1.1: `ProviderVerifier` (interface), `VerificationResult` (value
object), `VerificationOutcome` (enum), `VerificationException` (config-error
exception). Deferred: the three implementations (T1.2-T1.4), the registry and any
`WebhookRequest` DTO.

## Consequences

- **Positive:** the controller has a single, total `match` over three outcomes and
  never re-parses the body; an outage on PayPal's API is `Error` (e.g. `502`),
  never a false `401`; a missing secret is a loud `500`, never a silent `401`;
  the body is parsed once by the party that understands it; the interface leaks no
  provider header names and no secrets; mirrors existing house style (value object
  + factory exception, `final readonly`).
- **Negative / debt:** four small files for "just an interface". The `Error`
  outcome only has a real producer once PayPal (T1.4) lands; GitHub/Stripe verify
  locally and will only ever return `Valid`/`Invalid` — accepted, the seam is
  shaped for the hardest provider so the controller code written in T1.5 does not
  change when T1.4 arrives. `array $headers` remains untyped; revisit with a
  `WebhookRequest` DTO only if a second consumer or extra request metadata appears.
