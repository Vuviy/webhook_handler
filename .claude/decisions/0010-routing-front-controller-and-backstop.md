# 0010. Front-controller routing, header reconstruction and the global 500 backstop

- **Status:** Accepted
- **Date:** 2026-06-05

## Context

T1.5 shipped a PURE `App\Http\IngestionController::handle(string $provider, string
$rawBody, array $headers): IngestionResponse` (`app/src/Http/IngestionController.php`)
that touches no superglobal and no `php://input`. T1.6 must wire that controller into
the real HTTP entry point and make it the LAST task of Milestone 1.

The wiring is non-trivial for four reasons, each a genuine fork:

1. **Where parsing/routing logic lives.** nginx funnels every non-file request to
   `public/index.php` (`docker/nginx/conf.d/default.conf:7`), so all routing is in PHP.
   `index.php` itself cannot be unit-tested (it writes to globals and `echo`es). If the
   path/method/header parsing lives inline there, none of it is testable — yet that
   parsing is exactly where the security-relevant header reconstruction happens.

2. **`getallheaders()` is unavailable** in this PHP-FPM setup (verified: returns
   `false`). Headers must be reconstructed from `$_SERVER['HTTP_*']`. That transform is
   logic worth testing in isolation, and it must hand the controller an
   `array<string,string>` map (the controller lower-cases keys itself,
   `IngestionController.php:68`).

3. **The 400-vs-404 split.** The task title names `400`, but the controller already
   owns `404` for an unknown-but-present provider (`IngestionController.php:64`). These
   are two different failures and must not collapse into one.

4. **The global backstop.** The T1.5 review flagged NFR-1: no uncaught `Throwable` may
   ever escape with a stack trace or payload into the response body. Today `index.php`
   has no `try/catch` at all — a thrown exception would render PHP's default error page.

## Options considered

### Option A — Everything inline in `index.php` (parse path, method, headers, dispatch)
- Pros: fewest files; nothing new to autoload.
- Cons: the header-reconstruction transform and the 400/404/405 decisions become
  untestable (they live in a file that can only run under a live request); repeats the
  T1.5 mistake of putting logic where it can't be exercised; `index.php` grows past the
  "thin wiring script" intent of ADR 0002.

### Option B — A full `Router` class with a route table / dispatcher abstraction
- Pros: extensible; familiar shape.
- Cons: gross over-engineering for **two** routes (`GET /` health, `POST
  /webhooks/{provider}`); violates the standing rule "avoid abstractions that have only
  one implementation" (`php-conventions/SKILL.md:14`). A route table buys nothing here.

### Option C — One small testable `final` request VO + thin `index.php` wiring
- A `final` `App\Http\ServerRequest` value object built by a static
  `ServerRequest::fromGlobals(array $server, ?string $rawBody): self` factory that
  performs the `$_SERVER → header map` transform and exposes `method()`, `path()`,
  `headers()`, `rawBody()`. All the parsing logic that *can* be wrong lives here and is
  unit-testable by passing a fake `$server` array.
- `index.php` stays a ~25-line wiring script: build the request, match one of two
  routes with plain `parse_url` + a small regex, render the response, all inside one
  top-level `try/catch (\Throwable)` backstop.
- Pros: the risky logic is testable; `index.php` stays thin (honours ADR 0002); no
  premature Router abstraction; matches the house pattern of small single-purpose VOs.
- Cons: one new class. Acceptable — it carries real, testable behaviour.

## Decision

We chose **Option C**.

- **Routing** stays a hand-rolled `if`/regex match in `index.php` (two routes only — a
  Router class is unjustified, Option B rejected).
- **Parsing** (method, path, header reconstruction, raw body) moves into a testable
  `final` `App\Http\ServerRequest` VO (Option A rejected — that logic must be testable).
- **400 vs 404 split:**
  - `/webhooks/` or `/webhooks` with **no provider segment** → router returns **400**
    (the URL is structurally malformed; the controller can't even be addressed).
  - `/webhooks/{non-empty-segment}` → **delegate to the controller**, which returns
    `202/401/502/500`, and **404** for a present-but-unsupported provider
    (`/webhooks/bitbucket`). 404 stays the controller's job, exactly as T1.5 built it.
- **Method handling:** `/webhooks/{provider}` accepts **POST only**; any other method →
  **405** with an `Allow: POST` header. `GET /` → health 200; any other method on `/` →
  **405** with `Allow: GET`.
- **Backstop:** a single `try { dispatch } catch (\Throwable $e) { error_log; emit 500 }`
  wraps the whole dispatch in `index.php`. The 500 body is the constant
  `{"status":"error"}` — never `$e->getMessage()`, never the payload (NFR-1).
- **Deferred wiring:** the `IngestionController` (and therefore `Connection::fromConfig`
  + `Queue::fromConfig`, which open a MySQL and a Redis connection) is constructed
  **only inside the matched `POST /webhooks/{provider}` branch**, so health checks, 404
  routes and 405s open no DB/Redis connection.

## Consequences

- Positive: header reconstruction, method/path parsing and the 400 decision are
  unit-testable via `ServerRequest::fromGlobals($fakeServer, $fakeBody)`; `index.php`
  stays a thin, readable wiring script; no connection is opened unless a webhook is
  actually being ingested; no `Throwable` can leak internals into a response.
- Negative / debt: the route match is a literal `if`/regex in an untestable file — fine
  for two routes, but a third route type would be the moment to revisit a small Router.
  `ServerRequest` reads `$_SERVER` only via the injected `$server` argument, so it never
  becomes a hidden global dependency.
