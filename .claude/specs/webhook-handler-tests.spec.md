# Spec: Tests for verifiers + retry logic (T3.3)

- **Status:** Draft
- **Author:** backend-architect
- **Date:** 2026-06-06
- **Source:** `.claude/tasks/webhook-handler.tasks.md:30` (T3.3, size M)
- **Related ADR:** `.claude/decisions/0018-test-framework-and-suite-for-verifiers-and-retry.md`
- **Parent spec:** `.claude/specs/webhook-handler.spec.md` (AC-1, AC-3, AC-4; NFR-1, NFR-2)

## 1. Problem
The webhook handler is feature-complete (T0.1–T3.2) but has **no automated tests** and
**no test runner** (`composer.json:21-25` lists only phpstan/psalm/phpcs; no `app/tests/`,
no `phpunit.xml`). The two riskiest areas — provider **signature verification** (a
security boundary) and the **retry/DLQ policy** (the reliability boundary) — must be
locked behind a fast, deterministic unit suite so future changes cannot silently break
`hash_equals` usage, the Stripe replay window, the fail-closed outcomes, or the
3-attempts→DLQ transition. This task introduces the test toolchain and that suite.

## 2. Goals
- Add a standard test runner (PHPUnit, dev-only) and a place to put tests.
- Unit-cover all three verifiers' decision branches (Valid / Invalid / Error).
- Unit-cover the retry policy: exponential-backoff values, the attempt limit, and the
  exhaustion→DLQ transition.
- Keep the suite hermetic: no real Redis, no real MySQL, no network, no `sleep`.

## 3. Non-goals
- End-to-end / integration tests of the ingestion controller, routing, the worker loop,
  the dashboard, or dedupe (AC-2, AC-5, AC-6). Out of scope for "verifiers + retry".
- Testing `CurlHttpClient`'s real curl behaviour (it is the un-mockable edge; covered
  manually).
- Code-coverage gating / CI wiring.
- Any change to verifier or retry **behaviour** — tests pin existing behaviour; the only
  permitted production change is the testability seam in §6.

## 4. Functional requirements
- **FR-1** PHPUnit is available as a `require-dev` dependency and runnable in the `php`
  container.
- **FR-2** Tests live under `app/tests/`, namespace `App\Tests\`, mirroring `app/src/`.
- **FR-3** `app/phpunit.xml` defines a `unit` test suite pointing at `app/tests/`.
- **FR-4** GitHub verifier tests cover: valid signature → `Valid` with id/type from
  headers; wrong-secret/tampered-body → `Invalid('signature mismatch')`; missing
  `X-Hub-Signature-256` → `Invalid`; empty body → `Invalid`; valid signature but missing
  `X-GitHub-Delivery`/`X-GitHub-Event` → `Invalid` (fail-closed, not `Error`);
  a near-miss signature (correct length, one byte off) → `Invalid` (timing-safe path
  exercised).
- **FR-5** Stripe verifier tests cover: valid `t`+`v1` within tolerance → `Valid` with
  id/type from body; multiple `v1` where only the second matches → `Valid` (rotation);
  signature mismatch → `Invalid`; missing `Stripe-Signature` → `Invalid`; missing `t`
  → `Invalid`; missing `v1` → `Invalid`; non-numeric `t` → `Invalid`; timestamp outside
  tolerance (both past and future skew) → `Invalid('timestamp outside tolerance')`;
  valid signature over non-JSON / object-without `id`/`type` → `Invalid`. The clock is a
  fixed injected closure; the tolerance boundary is tested at `±tolerance` and just
  beyond.
- **FR-6** PayPal verifier tests cover (with a fake `HttpClient`): both calls succeed and
  PayPal returns `SUCCESS` → `Valid` with id/event_type from body; PayPal returns a
  non-`SUCCESS` status → `Invalid`; token call fails (throws `HttpException` / non-2xx /
  no `access_token`) → `Error`; verify call fails (throws / non-2xx / unreadable body)
  → `Error`; missing any of the five `paypal-*` headers → `Invalid`; empty body →
  `Invalid`; non-JSON body → `Invalid`.
- **FR-7** Retry tests cover: `delayFor(n)` returns a value in `[2^(n-1)·1, 2^(n-1)·2]`
  seconds for n=1,2,3 (equal-jitter range, base 2s), asserted over many iterations;
  `retryOrFail` with `attempt < 3` calls `markForRetry(id, attempt+1, error)` then
  `scheduleRetry(eventId, provider, attempt+1, readyAt)` — and the DB write happens
  **before** the queue write; `retryOrFail` at the exhaustion boundary
  (`attempt+1 > MAX_ATTEMPTS`) calls `deadLetter` instead (DB `markFailed` then
  `Queue::deadLetter(eventId, provider, attempt)`), and does **not** schedule a retry.
- **FR-8** All tests pass via `docker compose exec php vendor/bin/phpunit`, and existing
  `psalm`/`phpcs` still pass over `app/` including `app/tests/`.

## 5. Non-functional requirements
- **NFR-1** Hermetic & fast: no Redis, no MySQL, no network, no real sleeping. Test
  doubles only.
- **NFR-2** Deterministic: jitter asserted by **range**; time asserted via the injected
  fixed clock; collaborator effects asserted by recorded calls, never by wall clock.
- **NFR-3** Convention-clean: every test file `declare(strict_types=1)`, `final` test
  classes, PSR-12, full type hints (`php-conventions` skill).
- **NFR-4** Secrets in tests are obvious throwaway literals (e.g. `'test_secret'`); they
  are not real and are never printed in assertion messages.

## 6. Design

### Toolchain
- `app/composer.json`: add `"phpunit/phpunit": "^12"` to `require-dev` (implemented as `^12`, not
  `^11` — PHPUnit 12 supports PHP 8.3 and matches the `sebastian/diff:7.0.0` already locked via psalm;
  PHPUnit 11 conflicts with it — see ADR 0018 implementation notes); add an
  `autoload-dev` block mapping `"App\\Tests\\": "tests/"`. Run
  `docker compose exec php composer update phpunit/phpunit` (or `composer install` after
  editing) and `composer dump-autoload`.
- `app/phpunit.xml`: bootstrap `vendor/autoload.php`, one `<testsuite name="unit">`
  pointing at `./tests`, strict settings (fail on warning/notice), `colors="true"`.

### Layout (mirrors `app/src/`)
```
app/tests/
  Verification/
    GitHubVerifierTest.php
    StripeVerifierTest.php
    PayPalVerifierTest.php
  Queue/
    RetrySchedulerTest.php
  Support/                      # in-test doubles
    CallLog.php                 # shared ordered journal of calls (asserts DB-before-queue ordering)
    FakeHttpClient.php          # implements App\Http\HttpClient; scripts post() responses/throws
    FakeQueue.php               # implements the extracted App\Queue\RetryQueue; records calls
    RecordingEventWriter.php    # implements the extracted App\Repository\EventWriter; records calls
```

### Test doubles
- **FakeHttpClient** (`App\Tests\Support`): implements `App\Http\HttpClient`; constructed
  with a queue/list of canned `HttpResponse` objects or `HttpException` to throw, popped in
  call order (token call first, verify call second). Records the URLs/headers/bodies it was
  given for assertions. Hand-written, not a PHPUnit mock, because the two-step sequence is
  clearer scripted explicitly. `HttpResponse` is `final readonly` with
  `__construct(int status, string body)` (`HttpResponse.php`).
- **FakeQueue / RecordingEventRepository**: record `(method, args)` tuples so the test can
  assert exact calls, argument values, and **ordering** (FR-7 DB-before-queue). They
  implement the interfaces extracted in the testability seam below.

### Testability seam (the only production change — a seam, not feature logic)
`Queue` (`Queue.php:32`) and `EventRepository` are both `final`, so neither a subclass nor
a PHPUnit mock can stand in for them, and `RetryScheduler` depends on both concretely
(`RetryScheduler.php:39-41`, `:68`, `:101`). To make `RetryScheduler` honestly
unit-testable:
- Extract a minimal **`App\Queue\RetryQueue`** (implemented as `RetryQueue`, a role name in the
  house style, not `QueueInterface`) declaring exactly the methods `RetryScheduler` uses —
  `scheduleRetry()`, `deadLetter()`, `promoteDueRetries()` — and make `Queue implements RetryQueue`.
  Retype `RetryScheduler::__construct(RetryQueue $queue)`.
- Extract a minimal repository-write interface (e.g. **`App\Repository\EventWriter`**)
  declaring `markForRetry()` and `markFailed()`, implemented by `EventRepository`, and
  retype `RetryScheduler::retryOrFail()`/`deadLetter()` params to it.
- These changes touch **only** type declarations and add interface files; **no** method
  body, SQL, Redis call, or behaviour changes. If the engineer declines this seam, the
  fallback (ADR 0018, Decision 3b Option B) is a real-Redis + real-MySQL integration test
  for `RetryScheduler` only — record which path was taken in the learning note.

### Determinism specifics
- **Stripe clock:** pass `static fn (): int => 1_700_000_000` (any fixed value); build
  the `t` in the signature header relative to it. Test the window at exactly
  `±TOLERANCE_SECONDS` (300) and at `±301` to pin the boundary.
- **GitHub/Stripe signatures:** generate the expected `hash_hmac('sha256', ...)` inside
  the test with the same throwaway secret, so the "valid" case is self-consistent and the
  "tampered" case flips one byte/secret.
- **Jitter:** call `delayFor(n)` ~1000 times and assert every result is within
  `[2^(n-1), 2^(n)]` seconds (i.e. `D/2 .. D`, base 2s); assert min and max land at the
  bounds with high probability, but the hard assertion is the range membership.

### Running
```bash
docker compose exec php composer install            # picks up phpunit + autoload-dev
docker compose exec php composer dump-autoload
docker compose exec php vendor/bin/phpunit          # uses app/phpunit.xml
docker compose exec php vendor/bin/psalm
docker compose exec php vendor/bin/phpcs app/
```

## 7. Acceptance criteria
- [ ] **AC-1** `docker compose exec php vendor/bin/phpunit` runs the `unit` suite and all
      tests pass, exercising the FR-4..FR-7 case lists.
- [ ] **AC-2** The suite uses **no** real Redis, MySQL or network (greppable: no `new Redis`,
      no `new PDO`, no `CurlHttpClient`, no `sleep(`/`usleep(` in `app/tests/`).
- [ ] **AC-3** GitHub & Stripe "valid" cases prove the `Valid` outcome carries the correct
      event id/type; every "bad" case asserts `Invalid` (not `Error`), and PayPal transport
      failures assert `Error` (not `Invalid`) — locking the AC-1/§NFR fail-closed mapping.
- [ ] **AC-4** Retry tests prove `delayFor` ranges for n=1,2,3, that `attempt<3` schedules a
      retry with `attempt+1` and writes the DB **before** the queue, and that the exhaustion
      boundary dead-letters (DB `markFailed` + `Queue::deadLetter`) without scheduling.
- [ ] **AC-5** `psalm` and `phpcs` pass over `app/` (including `app/tests/`).

## 8. Risks & edge cases
- **Final classes block doubling** → resolved by the extracted interfaces (§6); if skipped,
  the retry suite cannot be a pure unit test (fallback: integration).
- **Jitter flakiness** → assert ranges, never exact delays (NFR-2).
- **Hidden time/network coupling** → none in verifiers (clock injected, HTTP behind
  interface) — confirmed in `StripeVerifier.php:61-66` / `PayPalVerifier.php:62-63`.
- **PHPUnit 11 vs PHP 8.3** → 11.x supports 8.3; pin `^11`, not a newer major needing 8.4+.
- **`composer update` drift** → prefer `composer require --dev` for just phpunit, or update
  only that package, to avoid bumping unrelated deps; verify `composer.lock` diff is minimal.
- **autoload-dev not regenerated** → tests "class not found"; remember `dump-autoload`.

## 9. Open questions
*(Resolved by the architect with defaults; the engineer may override and note it.)*
- **PHPUnit major version:** default **^11** (PHP 8.3 compatible). — resolved.
- **Mock library style:** default **hand-written fakes** in `app/tests/Support/` for
  `HttpClient`/`Queue`/repo (clearer for a junior, explicit call recording); PHPUnit
  `createMock()` allowed for interfaces where simpler. — resolved.
- **RetryScheduler isolation:** default **interface extraction + in-memory fakes** (pure
  unit); real-Redis integration is the documented fallback only. — resolved.
- **DB doubling for repo:** default **extract `EventWriter` interface + recording fake**;
  do not stand up MySQL for unit tests. — resolved.
- Should a separate, optional `integration` suite (real Redis/MySQL) be added later for
  AC-2/AC-5/AC-6? **Deferred** — out of T3.3 scope; note as a future task.
