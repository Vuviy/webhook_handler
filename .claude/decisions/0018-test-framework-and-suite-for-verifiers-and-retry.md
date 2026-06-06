# 0018. Test framework and test suite for verifiers + retry logic (T3.3): PHPUnit in require-dev, `app/tests/` unit suite with an in-memory fake `Queue`, no feature refactor

- **Status:** Accepted
- **Date:** 2026-06-06

## Context

T3.3 (`.claude/tasks/webhook-handler.tasks.md:30`, size **M**) is the last task in
Milestone 3: "Tests for verifiers + retry logic". The Definition of Done
(`tasks.md:32-35`) requires that AC-1..AC-6 pass and that conventions pass — but to
date the project has **zero automated tests** and **no test runner**:

- `app/composer.json:21-25` declares only `phpstan`, `psalm`, `squizlabs/php_codesniffer`
  in `require-dev`. There is no PHPUnit.
- There is no `app/tests/` directory and no `phpunit.xml` (verified: both absent).
- `vendor/bin` therefore has `phpstan`/`psalm`/`phpcs` but **not** `phpunit`.

The code under test already exists and was built test-aware in earlier subtasks, which
removes the two hardest isolation problems before we start:

- **Stripe time dependency is already injected.** `StripeVerifier::__construct` takes a
  `Closure(): int $clock` (`StripeVerifier.php:61-66`); production passes `time(...)`
  via `fromSecrets()` (`StripeVerifier.php:81`). A test can pass a fixed-clock closure
  and drive the tolerance window deterministically. ADR 0007 established this seam.
- **PayPal HTTP is already behind an interface.** `PayPalVerifier::__construct` takes
  `HttpClient` (`PayPalVerifier.php:62-63`), whose only method is
  `post(string,$headers,string): HttpResponse` and which throws `HttpException` only on
  transport failure (`HttpClient.php:17-31`). A test injects a fake `HttpClient` that
  returns canned `HttpResponse`s or throws — no network. ADR 0008 established this seam.
- **Retry math is pure and isolated.** `RetryScheduler::delayFor()` is pure arithmetic
  with `random_int` jitter (`RetryScheduler.php:113-119`); `retryOrFail()` /
  `deadLetter()` depend only on the `Queue` and `EventRepository` objects passed in
  (`RetryScheduler.php:39-105`), so both collaborators can be doubled.

So the genuine architectural choices T3.3 must settle are: (1) which test framework,
(2) how the suite is laid out and run inside Docker, (3) how each external dependency
(time, HTTP, Redis, MySQL) is isolated, and (4) what coverage is "enough" for an M task.

This is the last subtask, so whatever is chosen here also becomes the project's
permanent testing convention.

## Options considered

### Decision 1 — Test framework

#### Option A — PHPUnit in `require-dev`
- Pros: the de-facto PHP standard; a junior will meet it everywhere; rich, well-documented
  assertions, data providers (ideal for the many signature cases), mocks for `HttpClient`
  and `Queue`; integrates with `phpunit.xml`, CI and coverage later. A **dev** dependency
  is not an application framework, so it does not violate the "framework-free" rule — that
  rule is about the runtime architecture (`CLAUDE.md`), not the toolchain (we already use
  phpstan/psalm/phpcs the same way).
- Cons: one more `require-dev` entry and a `composer update`; a small amount of config.

#### Option B — A hand-rolled micro test-runner (a `bin/test.php` that asserts)
- Pros: zero new dependencies; "purest" reading of framework-free; full transparency.
- Cons: re-invents data providers, mocking, fixtures, readable diffs and failure reporting
  — exactly the wheel PHPUnit already is; un-idiomatic, so it teaches the junior a private
  dialect instead of an industry skill; more code to maintain than the tests themselves.

#### Option C — Nothing / manual verification only
- Pros: no work.
- Cons: fails the task and the DoD; gives no regression safety net for security-critical
  code (signature verification, replay window, DLQ exhaustion). Unacceptable.

### Decision 2 — Layout and how to run it in Docker

#### Option A — `app/tests/` mirroring `app/src/`, `App\Tests\` via `autoload-dev`, `app/phpunit.xml`, run via `docker compose exec php vendor/bin/phpunit`
- Pros: conventional PSR-4 test namespace kept out of the production autoloader
  (`autoload-dev`); directory mirrors `src/` so a test's home is obvious; one `phpunit.xml`
  pins config; runs in the same `php` container the app runs in (PHP 8.3, ext-redis,
  pdo_mysql all present — `docker/php/Dockerfile`), so "works in tests" == "works in prod".
- Cons: requires a `composer dump-autoload` after adding `autoload-dev`.

#### Option B — tests beside the classes / a flat `tests/` at repo root
- Cons: pollutes `src/` or sits outside the PSR-4 root and the container working dir
  (`/var/www/html` is `./app`, compose `working_dir`); awkward autoloading; non-standard.

### Decision 3 — Isolating external dependencies (the load-bearing one)

#### Option A (chosen for Stripe/PayPal) — use the seams that already exist
- Stripe: pass a fixed `fn(): int => $frozenNow` closure; no global time touched.
- PayPal: inject a **fake** `HttpClient` (a tiny in-test class implementing the interface)
  returning scripted `HttpResponse`s / throwing `HttpException`. Prefer a hand-written fake
  over PHPUnit's `createMock()` here because the two-call token-then-verify sequence is
  easier to script explicitly, but either is acceptable.

#### Decision 3b — RetryScheduler's Redis dependency: fake `Queue` vs real Redis
- **Option A (chosen) — unit-test against an in-memory fake/double of `Queue`.**
  `Queue` is `final` (`Queue.php:32`) so it cannot be subclassed; PHPUnit cannot mock a
  final class either. Resolve this by **introducing a thin `QueueInterface`** that both
  `Queue` and a test fake implement, and type `RetryScheduler` against the interface.
  - Pros: pure, fast, deterministic unit tests; asserts the exact `scheduleRetry(...)` /
    `deadLetter(...)` calls and arguments; no Redis/MySQL needed in the unit run; also
    improves the production design (depend on an abstraction). `EventRepository` is also
    `final`, so a parallel `EventRecorder`/repository interface (or a recording fake)
    is needed for the same reason — see Open questions / Consequences.
  - Cons: requires a small, in-scope production change (extract an interface). This is a
    seam extraction, **not** feature logic, and is the conventional way to make a final
    class testable; it is explicitly allowed as "make the code testable" rather than
    "write the feature".
- **Option B — integration test against the real Redis container.**
  - Pros: no interface extraction; exercises real ZADD/RPUSH semantics end to end.
  - Cons: slow, stateful, needs `redis` (and `db_webhook` for the repo) up; flaky on the
    jitter timing; couples a *unit* of retry policy to infrastructure. Better kept as an
    optional, separate, small integration check, not the primary T3.3 deliverable.

### Decision 4 — Coverage scope for an "M" task

Cover the security- and reliability-critical branches that map directly to AC-1/AC-3/AC-4
and the security NFRs; do **not** add ingestion/controller/worker/dashboard integration
tests (those are AC-2/AC-5/AC-6 end-to-end and are out of this task's "verifiers + retry"
scope — see spec Non-goals below). Explicit case list is in the spec.

## Decision

1. **Framework: PHPUnit** (Option A, Decision 1), added to `require-dev` (`^11`,
   compatible with PHP 8.3). A dev test tool is not an application framework, so this
   honours the framework-free rule exactly as phpstan/psalm/phpcs already do.
2. **Layout: `app/tests/` mirroring `app/src/`** (Option A, Decision 2), namespace
   `App\Tests\` registered under `autoload-dev`, config in `app/phpunit.xml`, executed
   with `docker compose exec php vendor/bin/phpunit`.
3. **Isolation (Decision 3):** reuse the existing clock seam for Stripe and the existing
   `HttpClient` seam for PayPal; for RetryScheduler, unit-test against **in-memory
   fakes** reached through **thin interfaces extracted from `Queue` and `EventRepository`**
   (both are `final`). The unit suite touches **no** Redis and **no** MySQL.
4. **Scope (Decision 4):** the bounded case list in
   `.claude/specs/webhook-handler-tests.spec.md` — valid/invalid/missing-header/empty-body
   per verifier, timing-safe path, Stripe tolerance + multi-`v1` + non-numeric `t`, PayPal
   success/invalid/error(token & verify failures), and the backoff values + exhaustion→DLQ
   transition for retry. No controller/worker/dashboard integration in T3.3.

## Consequences

- **Positive:** a fast, deterministic unit suite guarding the most security-sensitive code
  (`hash_equals`, replay window, fail-closed outcomes) and the retry/DLQ boundary;
  reusable testing convention for the whole repo; PHPUnit is a transferable skill for the
  junior owner; the interface extraction makes `RetryScheduler` honestly unit-testable and
  nudges the design toward depend-on-abstractions.
- **Negative / debt:**
  - A small **in-scope production change**: extract `QueueInterface` (implemented by
    `Queue`) and a repository-write interface (implemented by `EventRepository`), and
    retype `RetryScheduler`'s constructor/method params to the interfaces. Touch only the
    type declarations and the new interface files; do **not** alter any feature behaviour.
    If the implementing engineer prefers to avoid even this, the fallback is a real-Redis
    integration test (Decision 3b Option B) for `RetryScheduler` only — record which path
    was taken.
  - `delayFor()` uses `random_int` jitter, so assert on the **range** (`D/2 <= delay <= D`),
    not an exact value; for `retryOrFail`/`deadLetter` assert the **calls/args** on the fake
    Queue and the recorded repository writes, not the wall-clock score.
  - No end-to-end coverage of AC-2/AC-5/AC-6 from this task; those remain verified manually
    (per `CLAUDE.md` SOP) until a future integration-test task.

## Implementation notes (2026-06-06, engineer overrides)

Three small, sound deviations from the plan above, made during implementation and recorded here
(the spec §9 explicitly permits the engineer to override and note):

- **PHPUnit `^12`, not `^11`.** The plan pinned `^11` assuming a newer major needs PHP 8.4. It does
  not: PHPUnit 12 supports PHP 8.3. More decisively, the lock **already** pins `sebastian/diff:7.0.0`
  (pulled by `vimeo/psalm`, which accepts `^4||…||^8`). PHPUnit 11 requires `sebastian/diff:^6` and
  so **conflicts** with the existing lock, while PHPUnit 12 requires `^7` and resolves cleanly with
  **no downgrade** of the shared `sebastian/*` packages psalm/phpcs rely on. `^12` was therefore the
  lower-risk pin. Verified: `composer update phpunit/phpunit -W` installed `12.5.29`, suite green on
  PHP 8.3.31.
- **Interface named `App\Queue\RetryQueue`, not `QueueInterface`.** The house style names interfaces
  by ROLE without an `*Interface` suffix (`HttpClient`, `ProviderVerifier`, `Handler`, and the new
  `EventWriter`). `RetryQueue` — "the queue surface the retry policy needs" — matches that and keeps
  both new seams consistent. Contents are exactly as planned (`scheduleRetry`/`deadLetter`/
  `promoteDueRetries`).
- **Recording double named `RecordingEventWriter`, not `RecordingEventRepository`.** It implements
  `EventWriter`, not a repository, so the role name is the honest one.
