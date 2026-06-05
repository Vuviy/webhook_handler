# 0008. PayPal verifier: in-house HTTP seam and the Error-outcome boundary

- **Status:** Accepted
- **Date:** 2026-06-05

## Context

T1.4 implements `App\Verification\PayPalVerifier`. Unlike GitHub (T1.2) and Stripe
(T1.3), PayPal verification is **not local crypto**. To verify, we POST to PayPal's
`/v1/notifications/verify-webhook-signature` (after obtaining an OAuth2 bearer token
from `/v1/oauth2/token`) and read back `verification_status: SUCCESS | FAILURE`. This
makes T1.4 the **first and only producer of the `Error` outcome** the seam was shaped
for in T1.1: when verification cannot be *performed* (PayPal down, timeout, non-2xx,
token fetch failed), we must return `error(...)` (→ 502), distinct from PayPal saying
`FAILURE` (a real bad signature → `invalid(...)` → 401).

Two genuine architectural forks fall out of this and need recording:

1. **How do we make the remote HTTP call, given it MUST be mockable in tests** (the
   suite must never touch PayPal)? Verified environment facts: no Guzzle / PSR-18
   client / Symfony HttpClient is installed; only `psr/http-message`, `psr/http-factory`,
   `psr/clock`, `psr/log`, `psr/container` are present transitively. The container has
   `ext-curl` and `ext-openssl`. Project ethos is framework-free, hand-rolled
   (`CLAUDE.md`).
2. **Where exactly is the boundary between `error` and `invalid`** for every remote
   failure mode, so a hostile/unlucky request never becomes a 5xx but a genuine outage
   never gets mislabelled as a forged signature (401)?

Constraints carried in from the existing code:
- `verify()` must NEVER throw for routine/malformed input (interface doc,
  `ProviderVerifier.php:36-42`); only misconfiguration throws `VerificationException`.
- `VerificationResult::valid()` throws on empty ids (`VerificationResult.php:46-50`) —
  guard before calling.
- NFR-3 targets ack < 200ms with "no downstream business calls"
  (`webhook-handler.spec.md:48`). PayPal verification is itself a downstream call, so
  PayPal ingestion is inherently slower than GitHub/Stripe — see Consequences.

## Options considered

### HTTP seam

#### Option A — Tiny in-house `HttpClient` interface + `CurlHttpClient`
A minimal interface (one `post()` shaped exactly to PayPal's needs) returning a small
`HttpResponse` value object (int status + string body). `CurlHttpClient` is the curl
implementation; the verifier depends only on the interface.
- Pros: Mockable without a network (inject a fake `HttpClient` in tests). Matches the
  framework-free ethos and the existing "thin typed wrapper around an extension" pattern
  already used by `App\Queue\Queue` over phpredis. No new Composer dependency. Surface
  is tiny — only what PayPal needs, not a general HTTP toolkit.
- Cons: We hand-roll curl (timeouts, TLS, error mapping) instead of reusing a vetted
  client. One more small abstraction to own.

#### Option B — Pull Guzzle / a PSR-18 client
- Pros: Battle-tested, retries/middleware available, PSR-18 is swappable.
- Cons: Against the stated framework-free ethos (`CLAUDE.md`); a heavy dependency for a
  single POST + token call; larger attack/upgrade surface; the team is deliberately
  learning the primitives. Overkill for two endpoints.

#### Option C — Call curl directly inside the verifier
- Pros: Least code, no new types.
- Cons: **Untestable without hitting PayPal** — the verifier's branching (success /
  FAILURE / transport error / non-2xx / timeout) could not be exercised in CI. Mixes
  transport concerns into verification logic. Rejected on testability alone.

### Error-outcome boundary

#### Option D — Map every non-SUCCESS to `invalid` (fail closed, simple)
- Pros: Dead simple; always 401 on anything that isn't a clean SUCCESS.
- Cons: Loses the very distinction T1.1 built the `Error` outcome for. A PayPal outage
  would tell a legitimate sender "your signature is invalid" (401) and the event would
  be dropped instead of being retried/surfaced as our fault (502). Wrong operationally.

#### Option E — Transport/timeout/non-2xx/token-failure → `error` (502); `FAILURE` and malformed request → `invalid` (401)
- Pros: Honest semantics. 502 says "we could not verify, not your fault — retry";
  401 says "we verified and it is bad / your request is malformed". Lets the controller
  (T1.5) and PayPal's own retry behave correctly. Uses the seam as designed.
- Cons: Slightly more branching; requires the HTTP seam to distinguish transport failure
  from a 2xx-with-body. (The `HttpResponse`/exception shape in Option A handles this.)

## Decision

**HTTP seam: Option A** — a tiny in-house `App\Http\HttpClient` interface with a
`CurlHttpClient` implementation and an `HttpResponse` value object, injected into
`PayPalVerifier`. It is the only option that is both mockable and faithful to the
framework-free ethos, and it mirrors the existing `Queue`-over-phpredis pattern.

**Error boundary: Option E.** Concretely:

| Situation | Outcome | HTTP |
| --- | --- | --- |
| Missing PayPal config (webhook id / client id / client secret / api base) | `VerificationException` (at `fromSecrets`) | 500 |
| Empty body / non-JSON body / JSON not an object | `invalid` | 401 |
| Any required `paypal-*` transmission header missing/blank | `invalid` | 401 |
| OAuth token fetch: transport error, timeout, non-2xx, or missing `access_token` | `error` | 502 |
| Verify call: transport error, timeout, or non-2xx | `error` | 502 |
| Verify response not JSON / missing `verification_status` | `error` | 502 |
| `verification_status: FAILURE` | `invalid` | 401 |
| `verification_status: SUCCESS` but body missing `id` / `event_type` | `invalid` | 401 |
| `verification_status: SUCCESS` + id & type present | `valid` | 202 |

The `CurlHttpClient` throws a transport exception (e.g. `App\Http\HttpException`) only
for connect/timeout/curl-level failures; a completed HTTP exchange (any status code)
returns an `HttpResponse`. The verifier catches the transport exception and maps it to
`error(...)`; it inspects `HttpResponse::status()` to decide 2xx vs non-2xx. **No curl
or transport exception is ever allowed to escape `verify()`** (interface contract).

Security ordering note: PayPal requires us to parse the body into JSON *before*
verification, because the verify request embeds `webhook_event` (the parsed event). This
does not violate the golden rule "don't trust the payload before verifying": we only
*forward* the parsed body to PayPal; we do not extract `id`/`event_type` or act on the
contents until `verification_status: SUCCESS`. SSRF: we never fetch `paypal-cert-url`
ourselves — PayPal does, server-side — so there is no SSRF surface here; we just forward
the header value.

## Consequences

- **Positive:** PayPal verification is fully unit-testable with a fake `HttpClient`
  (success, FAILURE, transport error, non-2xx, timeout, malformed responses). The
  `Error` outcome finally has a real producer, validating the T1.1 seam. No new runtime
  dependency. `App\Http\HttpClient` is reusable later (e.g. a real
  PayPal handler in T2.x), but is introduced minimally — only a `post()` for now.
- **Negative / debt:**
  - We own curl details: connect timeout (2s) and total timeout (5s) are mandatory so a
    hung PayPal cannot wedge an FPM worker; a timeout maps to `error` (502).
  - **NFR-3 (< 200ms ack) is structurally unattainable for PayPal**: ingestion makes two
    remote round-trips (token + verify). This is accepted for T1.4 — NFR-3 was written
    for "no downstream business calls", and PayPal's scheme makes the verification itself
    a downstream call. Flagged, not redesigned. Future option (out of scope): move PayPal
    verification into the worker (enqueue-then-verify) or cache the OAuth token in Redis
    across requests. Recorded as an open question for later, not decided here.
  - We fetch a fresh OAuth token on every webhook (2 calls/webhook). In the synchronous
    FPM model each request is a fresh process making exactly one verify call, so an
    in-process token cache buys nothing. Cross-request caching (Redis/APCu) is deferred:
    it adds moving parts and stores a near-secret for marginal gain at current volume.
