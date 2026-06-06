# Tasks: Tests for verifiers + retry logic (T3.3)

Derived from `.claude/specs/webhook-handler-tests.spec.md`.
ADR: `.claude/decisions/0018-test-framework-and-suite-for-verifiers-and-retry.md`.
This is the breakdown of the single task **T3.3** from
`.claude/tasks/webhook-handler.tasks.md:30`. Order matters — top to bottom.

## Milestone A: Toolchain
- [ ] A.1 — Add `"phpunit/phpunit": "^11"` to `require-dev` in `app/composer.json`; add
      `autoload-dev` mapping `"App\\Tests\\": "tests/"`. Run
      `docker compose exec php composer update phpunit/phpunit` (minimal lock diff) then
      `composer dump-autoload`. · S · depends: —
- [ ] A.2 — Add `app/phpunit.xml`: bootstrap `vendor/autoload.php`, `<testsuite name="unit">`
      → `./tests`, strict (fail on warning/notice/deprecation), `colors="true"`. Verify with
      `docker compose exec php vendor/bin/phpunit --list-suites`. · S · depends: A.1

## Milestone B: Testability seam (production change — seams only, no behaviour change)
- [ ] B.1 — Extract `App\Queue\QueueInterface` with `scheduleRetry()`, `deadLetter()`,
      `promoteDueRetries()`; make `Queue implements QueueInterface`; retype
      `RetryScheduler::__construct(QueueInterface $queue)`. No method bodies change.
      `php -l` + `psalm`. · S · depends: —
- [ ] B.2 — Extract `App\Repository\EventWriter` with `markForRetry()`, `markFailed()`;
      make `EventRepository implements EventWriter`; retype the `EventRepository`
      params of `RetryScheduler::retryOrFail()`/`deadLetter()` to `EventWriter`. No SQL/
      behaviour change. `php -l` + `psalm`. · S · depends: —
      *(If declined: skip B.1/B.2 and do C.5 as a real-Redis+MySQL integration test instead;
      record the choice in the learning note — ADR 0018 Decision 3b.)*

## Milestone C: Test doubles + suites (the actual M work)
- [ ] C.1 — `app/tests/Support/FakeHttpClient.php` implements `App\Http\HttpClient`; scripts
      `post()` to pop canned `HttpResponse`/throw `HttpException` in call order; records
      calls. · S · depends: A.2
- [ ] C.2 — `app/tests/Support/FakeQueue.php` (implements `QueueInterface`) and
      `RecordingEventRepository.php` (implements `EventWriter`): record `(method, args)` in
      call order for argument + ordering assertions. · S · depends: A.2, B.1, B.2
- [ ] C.3 — `app/tests/Verification/GitHubVerifierTest.php` — FR-4 cases (valid, mismatch,
      missing sig header, empty body, missing delivery/event headers, one-byte-off
      near-miss). · M · depends: A.2
- [ ] C.4 — `app/tests/Verification/StripeVerifierTest.php` — FR-5 cases with a fixed clock
      closure: valid, multi-`v1` rotation, mismatch, missing header/`t`/`v1`, non-numeric
      `t`, tolerance boundary (±300 ok, ±301 reject, past+future), non-JSON / missing
      id/type. · M · depends: A.2
- [ ] C.5 — `app/tests/Verification/PayPalVerifierTest.php` — FR-6 cases with FakeHttpClient:
      SUCCESS→Valid, non-SUCCESS→Invalid, token failure→Error, verify failure→Error,
      missing transmission header→Invalid, empty/non-JSON body→Invalid. · M · depends: C.1
- [ ] C.6 — `app/tests/Queue/RetrySchedulerTest.php` — FR-7: `delayFor(1..3)` range over
      ~1000 runs; `attempt<3` → `markForRetry(id,attempt+1,err)` then
      `scheduleRetry(...,attempt+1,...)` with DB-before-queue ordering; exhaustion boundary
      → `markFailed` + `Queue::deadLetter(...,attempt)` and NO schedule. · M · depends: C.2

## Milestone D: Verify & close
- [ ] D.1 — Run `docker compose exec php vendor/bin/phpunit`; all green. Capture real output
      for the mentor note. · S · depends: C.3,C.4,C.5,C.6
- [ ] D.2 — Run `docker compose exec php vendor/bin/psalm` and
      `docker compose exec php vendor/bin/phpcs app/` (incl. `app/tests/`); fix lint, not
      behaviour. · S · depends: D.1
- [ ] D.3 — Grep `app/tests/` to confirm no `new Redis` / `new PDO` / `CurlHttpClient` /
      `sleep(` / `usleep(` (AC-2 hermetic check). · S · depends: D.1
- [ ] D.4 — Mark T3.3 `[x]` in `.claude/tasks/webhook-handler.tasks.md` with date + links to
      review / ADR 0018 / learning note; tick DoD AC checkboxes that are now proven. · S ·
      depends: D.1,D.2,D.3

## Definition of done
- [ ] `vendor/bin/phpunit` green; FR-4..FR-7 cases present (spec AC-1, AC-3, AC-4).
- [ ] Suite is hermetic — no Redis/MySQL/network/sleep (AC-2).
- [ ] `psalm` + `phpcs` pass over `app/` incl. `app/tests/` (AC-5).
- [ ] Reviewed by `code-reviewer`; learning note written by `learning-mentor` (records the
      seam-vs-integration choice and shows every command + output).
