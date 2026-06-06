# 0016. Read-only monitoring dashboard (T3.1): JSON-API-first shape, route placement, the new read methods, and how it wires into the existing front controller without breaking the no-DB-on-health invariant

- **Status:** Accepted
- **Date:** 2026-06-06

## Context

T3.1 is the first subtask of Milestone 3. It must deliver the spec's read-only monitoring
view — **counts by status, recent failures, DLQ size** (FR-10 `webhook-handler.spec.md:40`,
AC-5 `:85`) — over the same Nginx/PHP-FPM front the ingestion path already uses. The DLQ
**re-queue / drain** path is explicitly the *next* subtask, T3.2 (`webhook-handler.tasks.md:29`,
and ADR 0014's "drain / re-queue path is T3.2" scope fence). T3.1 only *reads*; it never pops,
re-queues, or mutates anything.

Everything T3.1 needs to *report on* already exists and is owned in one place each:

- **Status counts + recent failures** live in MySQL `webhook_events` — the audit log written
  across the whole worker lifecycle (`insertReceived` → `markProcessing` → `markProcessed` /
  `markForRetry` / `markFailed`, `EventRepository.php:42-187`). The `status` column is the
  `ENUM(received,processing,processed,failed)` and there is an `idx_status` index plus an
  `idx_created_at` index (migration 0001, restated in the task brief). `last_error`, `provider`,
  `event_type`, `attempts`, `updated_at`, `created_at` are all already persisted per row (FR-9).
- **DLQ size** lives in Redis: `webhooks:dlq` is a list, RPUSH'd by `Queue::deadLetter()`
  (`Queue.php:215-222`); `Queue` already owns that key as `private const DLQ = 'webhooks:dlq'`
  (`Queue.php:50`) and already has the parallel `size()` for the main queue
  (`lLen(self::QUEUE)`, `Queue.php:127-130`). There is **no** read accessor for the DLQ list yet.

Constraints carried in from the existing code that *shape* this decision:

1. **The front controller funnels everything to one PHP entry point** and renders a single,
   hardcoded content type. `dispatch()` returns the triple `array{0:int,1:?string,2:?string}`
   = `[status, body, Allow]` (`index.php:83-126`), and the render block *unconditionally* sets
   `header('Content-Type: application/json; charset=utf-8')` (`index.php:66`). An HTML page
   therefore cannot be emitted without **changing that render contract** (a 4th tuple slot for
   content type, or some equivalent). A JSON response needs **no** such change.
2. **The no-connection-on-unrelated-routes invariant.** `dispatch()` builds the controller and
   its DB/Redis connections **only inside the matched webhook-POST branch** (`index.php:112-116`),
   precisely so that "a Redis outage must not break a health check" (`index.php:80-82`). The
   health route (`GET /`) deliberately opens **no** DB/Redis (`index.php:91-97`). The dashboard
   route MUST inherit this discipline: it opens DB/Redis **only when the dashboard path is
   matched**, never for health / 404 / 405 / webhook POSTs.
3. **The Throwable backstop is the only error story.** Any throw from building or running the
   dashboard (PDO connect down, Redis down, a SQL error) propagates to the `try/catch (\Throwable)`
   in `index.php:54-63`, which logs the full trace to the error log and returns a bodyless-ish
   `500 {"status":"error"}` — never leaking a message, trace, payload or secret (NFR-1). The
   dashboard must rely on exactly this; it must not invent its own error rendering that could leak.
4. **`GET /` is "the dashboard" per the environment.** `docker-compose.yml` documents
   "Dashboard: http://localhost/" and CLAUDE.md's Common-commands block repeats it. Today `GET /`
   returns the **health JSON** (`index.php:91-97`). So the dashboard's home and the health probe
   currently collide on the same path — that collision must be resolved here.
5. **`EventRepository` owns all `webhook_events` SQL; `Queue` owns all Redis keys.** New read
   queries belong as new methods on `EventRepository`; the DLQ length belongs as a new method on
   `Queue` (mirroring `size()`). No SQL or Redis key may leak into a controller (the same
   single-ownership rule ADRs 0005/0012/0014 enforced).
6. **This is an INTERNAL monitoring view, not a provider-facing endpoint.** NFR-1 forbids
   response bodies on the *ingestion* path from carrying payloads/secrets; for the dashboard the
   brief relaxes this to "MAY show event metadata (provider, event_type, status, last_error,
   timestamps) for recent failures; MUST NOT dump the raw `payload` column." `last_error` is a
   human reason string the worker already chose to persist for exactly this view (FR-9); it is in
   scope. The `payload` LONGTEXT (raw signed bytes) is **out** of scope and must never be selected.

Three genuine choices arise: **(D1)** the *shape* of the dashboard (server-rendered HTML vs JSON
API vs JSON + thin static HTML); **(D2)** the *route placement* and how it coexists with health;
**(D3)** the exact read queries / methods and their column safety.

## Options considered

### Decision 1 — the SHAPE of the dashboard

#### Option A — JSON API endpoint only (no server-rendered markup in T3.1)
- The dashboard route returns `application/json` with `{counts:{received,processing,processed,
  failed}, recent_failures:[…], dlq_size:N}`. Rendering for a human is left to `curl`/jq, a
  browser JSON view, or a later static front (which can be a trivial T3.x follow-up).
- Pros: **Zero change to the front controller's render contract** — it already hardcodes
  `application/json` (`index.php:66`) and the existing `[status, body, allow]` triple already
  carries an encoded-JSON body, exactly like every other route. The whole task collapses to:
  two `EventRepository` read methods + one `Queue::deadLetterSize()` + one tiny controller +
  one route branch. It composes cleanly with the Throwable backstop with **no new error path**.
  It is honestly testable (the controller returns a value object / array; assert the JSON).
  Smallest review surface, fewest moving parts — the right default for a junior-learning,
  framework-free project. Naturally extends to T3.2 (a re-queue button can POST and re-read this
  same JSON).
- Cons: not a "pretty" human page on its own. A human hitting `http://localhost/` in a browser
  sees raw JSON, which is *usable* (browsers render JSON readably) but not a styled dashboard.
  Mitigated: a static HTML shell that `fetch()`es this JSON is a clean, separable add-on, and the
  spec's open question explicitly lists "JSON API + static front" as an acceptable scope.

#### Option B — server-rendered HTML page (PHP echoes a styled `<html>` table)
- The dashboard controller builds an HTML string; the route returns it with
  `Content-Type: text/html`.
- Pros: one URL gives a human-readable page with no second request and no JS.
- Cons: **forces a change to the render contract** — `index.php` hardcodes
  `application/json` (`index.php:66`) and the triple has no content-type slot, so we must widen
  the tuple to a 4-tuple (or add a content-type field) and branch the header — a structural edit
  to the carefully-commented front controller that every other route must keep tolerating. It
  mixes HTML templating into a framework-free, template-engine-free codebase (hand-rolled
  `htmlspecialchars()` escaping of `last_error`/`event_type` becomes a **new XSS surface** the
  JSON path simply does not have). More code, more escaping discipline, broader review surface —
  poor cost/benefit for T3.1's three numbers, and it complicates the very entry point ADR 0010
  kept minimal.

#### Option C — JSON API endpoint **plus** a minimal static `index.html`
- Ship Option A's JSON endpoint, and add one static HTML file (served by Nginx as a plain file
  or echoed) that `fetch()`es the JSON and renders a table client-side.
- Pros: human-friendly page *and* a clean data API; the API stays pure/testable; escaping moves
  to the browser's DOM API (`textContent`) so no server-side XSS surface.
- Cons: introduces a second artifact (static HTML/JS) and a Nginx-serving question (the current
  `try_files … /index.php` funnels everything to PHP — `docker-compose`/nginx note in the brief —
  so serving a real static file needs a location rule or a PHP-emitted HTML shell). That is more
  than T3.1's "counts, recent failures, DLQ size" strictly requires, and it re-opens the
  render-contract / content-type question for the shell. Reasonable as a **follow-up**, but
  scope-creep for this subtask.

### Decision 2 — ROUTE placement and coexistence with the health probe

The dashboard "is" `http://localhost/` per docker-compose, but `GET /` already serves health and
**must keep opening no DB/Redis** (Constraint 2). Three placements:

#### Option D — dashboard at `GET /`, move the health JSON to `GET /health`
- `GET /` builds the dashboard (opens DB + Redis); `GET /health` keeps the current
  zero-dependency health JSON (`new Health()->status()`, no connections).
- Pros: matches the documented "Dashboard: http://localhost/" exactly — a human opening the root
  sees the monitoring data. `/health` is the conventional, self-documenting path for a liveness
  probe, and keeping it dependency-free preserves the invariant that a Redis/DB outage never
  breaks the probe (the whole reason `dispatch()` is structured as it is, `index.php:80-82`).
- Cons: **moves an existing endpoint** — anything currently probing `GET /` for health (none in
  this repo; greenfield deploy) would need to point at `/health`. Acceptable and is the standard
  convention; documented in Consequences.

#### Option E — dashboard at `GET /dashboard`, leave health at `GET /`
- Pros: smallest diff (pure addition, health untouched), no endpoint moves.
- Cons: **contradicts the documented `http://localhost/` dashboard URL** — the root would stay a
  health JSON and a human would have to *know* to type `/dashboard`. Re-introduces a doc/behaviour
  mismatch the brief explicitly asks to resolve. Health-at-root is also a weaker convention than
  health-at-`/health`.

#### Option F — content-negotiate on `GET /` (health for probes, dashboard for browsers)
- Branch on `Accept:` / `User-Agent` to serve health JSON to probes and the dashboard to humans
  at the same `/`.
- Cons: fragile and surprising (Accept-header sniffing, per-client behaviour), and it would force
  the *health* branch to sometimes open DB/Redis depending on a header — directly violating the
  no-connection-on-health invariant. Over-engineered. Rejected.

### Decision 3 — the read queries, the new methods, and column safety

Both data sources need a new read accessor in the class that owns them.

#### Option G — counts via a single grouped query; recent failures via an indexed, column-pinned, LIMITed query; DLQ size via `lLen`
- **`EventRepository::countsByStatus(): array<string,int>`** —
  ```sql
  SELECT status, COUNT(*) AS total
  FROM webhook_events
  GROUP BY status
  ```
  One round-trip for all four buckets, covered by `idx_status` (the GROUP BY scans the index, not
  the table). The controller normalises the result into a fixed shape with **all four enum keys
  present and defaulted to 0** (`received, processing, processed, failed`), so a status with no
  rows yet still reports `0` rather than being absent — a stable JSON contract for the dashboard.
- **`EventRepository::recentFailures(int $limit): array<int,array{...}>`** —
  ```sql
  SELECT id, provider, event_type, attempts, last_error, created_at, updated_at
  FROM webhook_events
  WHERE status = 'failed'
  ORDER BY updated_at DESC
  LIMIT :limit
  ```
  `WHERE status = 'failed'` uses `idx_status`; `updated_at` is the moment the row went terminal
  (it has `ON UPDATE CURRENT_TIMESTAMP`, migration 0001 — `markFailed` does not set it explicitly
  for exactly this reason, `EventRepository.php:179-187`), so "most recent failures first" is the
  correct ordering for an operator. **`payload` is deliberately NOT selected** (Constraint 6,
  NFR-1) — the SELECT list is pinned to safe metadata columns only; `last_error` (the human reason
  the worker chose to persist) and `attempts` ARE included because that is exactly what FR-10's
  "recent failures" is for. `:limit` is a bound parameter with a sane default (see D-defaults).
  - **`LIMIT :limit` binding note (carry into implementation):** with
    `ATTR_EMULATE_PREPARES => false` (`Connection.php:32`) MySQL will reject a *string*-bound
    LIMIT parameter, so the limit must be bound as `PDO::PARAM_INT` (or the validated integer
    inlined after a hard `(int)` cast / range-clamp). This is a known real-prepared-statement
    gotcha, flagged here so the implementer does not bind it as a string and get a 500.
- **`Queue::deadLetterSize(): int`** — `return (int) $this->redis->lLen(self::DLQ);` — the exact
  mirror of `size()` (`Queue.php:127-130`), keeping the `webhooks:dlq` key sealed in `Queue`
  (Constraint 5). Read-only `lLen`; it does NOT pop or drain (that is T3.2).
- Pros: each query is index-served and single-round-trip; the recent-failures SELECT is
  column-pinned so `payload` can never leak by accident (safer than `SELECT *`); counts are
  normalised to a complete, stable shape; DLQ size reuses the established `lLen` pattern and
  single-ownership. Minimal, idiomatic, matches every existing `EventRepository`/`Queue` method.
- Cons: counts and DLQ size are read from two different stores in one request, so they are a
  point-in-time snapshot that is *eventually* consistent with each other (a job can move between
  the two reads). Accepted: a monitoring snapshot does not need cross-store atomicity, and the
  numbers are individually correct at read time.

#### Option H — compute counts in PHP by selecting statuses, or `SELECT *` for failures
- Pros: none material.
- Cons: pulling rows to count them is wasteful and unindexed-friendly; `SELECT *` would drag the
  `payload` LONGTEXT across the wire and risk leaking it into the response (the precise NFR-1
  hazard). Rejected in favour of G's grouped count and pinned column list.

## Decision

- **D1: Option A — JSON API endpoint only for T3.1.** The dashboard route returns
  `application/json` with `{counts, recent_failures, dlq_size}`, requiring **no change** to the
  front controller's render contract or the Throwable backstop. A styled human front (static
  `index.html` that `fetch()`es this JSON — Option C) is a clean, separable follow-up and is
  recorded as the recommended next step, not built in T3.1. Server-rendered HTML (Option B) is
  rejected for forcing a content-type change and adding a server-side XSS/escaping surface for
  three numbers.
- **D2: Option D — dashboard at `GET /`, health moved to `GET /health`.** Matches the documented
  `http://localhost/` dashboard URL; `/health` keeps the zero-dependency health JSON
  (`new Health()->status()`, opens no DB/Redis), preserving the invariant that an infra outage
  never breaks the liveness probe. `GET /` now builds the dashboard controller and its DB+Redis
  connections **inside its own matched branch only** — exactly mirroring how the webhook-POST
  branch scopes its connections (`index.php:112-116`) — so 404/405/`/health`/webhook routes still
  open nothing. Non-`GET` on `/` continues to return `405 Allow: GET`.
- **D3: Option G — the three read accessors above.** `EventRepository::countsByStatus()`
  (grouped, `idx_status`, normalised to all four keys), `EventRepository::recentFailures(int)`
  (indexed, `ORDER BY updated_at DESC`, `LIMIT :limit` bound as **PARAM_INT**, **safe columns
  only — never `payload`**), and `Queue::deadLetterSize()` (`lLen(self::DLQ)`, mirror of
  `size()`). A new `DashboardController` (in `App\Http`, `final`, `declare(strict_types=1)`)
  takes an `EventRepository` and a `Queue`, calls the three accessors, and returns an
  `IngestionResponse`-style `(status, JSON body)` — reusing the existing response value object or
  a tiny sibling — so `dispatch()` renders it through the unchanged JSON path. Any throw (DB/Redis
  down, SQL error) propagates to the existing `try/catch (\Throwable)` 500 backstop; the dashboard
  invents NO error rendering of its own (no leak — NFR-1).

### Resolved open questions (defaults the architect set, per SOP)

- **Recent-failures `LIMIT`:** default **20**, exposed as a controller constant
  (`RECENT_FAILURES_LIMIT = 20`). Rationale: enough to see the current failure burst at a glance,
  small enough to keep the response tiny and the query cheap. No `?limit=` query parameter in
  T3.1 (keep the surface minimal; add later if needed, validated/clamped).
- **JSON response shape (the contract a future static front consumes):**
  ```json
  {
    "counts":   { "received": 0, "processing": 0, "processed": 0, "failed": 0 },
    "recent_failures": [
      { "id": 1, "provider": "stripe", "event_type": "payment_intent.failed",
        "attempts": 3, "last_error": "…", "created_at": "…", "updated_at": "…" }
    ],
    "dlq_size": 0
  }
  ```
  All four `counts` keys always present (defaulted to 0). `recent_failures` never contains
  `payload`.
- **HTTP status / method:** dashboard is `GET /` → `200` on success; non-`GET` → `405 Allow: GET`
  (unchanged from today). Errors → `500` via the existing backstop only.
- **Caching / auth:** none in T3.1. It is an internal view behind the local Docker network; the
  spec's Non-goals exclude auth/multi-tenant concerns (`webhook-handler.spec.md:22-26`). Flagged
  as an Open question for any non-local deployment, not solved here.

### Front-controller diff (structure only — no feature code)

```
// dispatch(): GET / now serves the dashboard (opens DB+Redis in THIS branch only);
// health moves to its own zero-dependency route.
if ($path === '/health') {
    if ($method !== 'GET') { return [405, IngestionResponse::rejected(405)->body(), 'GET']; }
    return [200, json_encode((new Health())->status(), JSON_THROW_ON_ERROR), null]; // no DB/Redis
}

if ($path === '/') {
    if ($method !== 'GET') { return [405, IngestionResponse::rejected(405)->body(), 'GET']; }
    $controller = new DashboardController(
        new EventRepository(Connection::fromConfig($config->database())),  // built ONLY here
        Queue::fromConfig($config->redis()),
    );
    $response = $controller->handle();
    return [$response->statusCode(), $response->body(), null]; // unchanged JSON render path
}
```

The render block (`index.php:65-72`), the Throwable backstop (`index.php:54-63`), the webhook-POST
branch, the `/webhooks` 400 branch and the 404 fall-through are **UNCHANGED**. The change is
additive: one moved route (`/health`), one rewritten `/` branch, three new owner-class methods,
one new controller. No new content type, no new error path.

## Consequences

- **Positive:**
  - **FR-10 / AC-5 satisfied** with the smallest possible delta: counts by status, recent
    failures, and DLQ size are all reported, each from the class that owns its store.
  - **No change to the front controller's render contract or its Throwable backstop** — the
    dashboard rides the existing JSON path and the existing 500 story (no NFR-1 leak path added).
  - **The no-connection-on-unrelated-routes invariant is preserved**: DB/Redis are opened only
    inside the matched `GET /` branch; `/health`, 404, 405 and webhook POSTs open nothing, and the
    liveness probe (`/health`) stays dependency-free.
  - **Single ownership upheld**: SQL stays in `EventRepository`, the `webhooks:dlq` key stays
    sealed in `Queue` (`deadLetterSize()` mirrors `size()`), no key/SQL leaks into the controller.
  - **No payload/secret leak**: the recent-failures SELECT is column-pinned to safe metadata and
    never touches `payload`; `last_error` is included by design as the operator-facing reason.
  - **Queries are index-served** (`idx_status` for the grouped count and the failed filter;
    `updated_at` ordering for recency), single-round-trip, and the counts shape is stable.
  - **Composes with T3.2**: the same JSON endpoint and the DLQ size are exactly what a re-queue
    button/CLI will read and re-read; nothing here blocks it.

- **Negative / debt:**
  - **No styled human page in T3.1** — the root returns JSON. The recommended follow-up is a
    static `index.html` that `fetch()`es it (Option C), which also keeps escaping on the browser
    side (no server-side XSS surface). Deferred deliberately, not forgotten.
  - **`GET /` health moves to `/health`** — a behaviour change for anything that probed the root
    (none in this greenfield repo). Standard convention; documented.
  - **Counts (MySQL) and DLQ size (Redis) are a non-atomic snapshot** — a job can move between the
    two reads, so the two numbers are only eventually consistent with each other. Acceptable for a
    monitoring view; each number is correct at its own read time.
  - **`LIMIT` must be bound as `PARAM_INT`** under `ATTR_EMULATE_PREPARES => false`, or the query
    500s — a real gotcha flagged for the implementer, not a design risk.
  - **No auth/caching** — fine for the local internal view (spec Non-goals); must be revisited
    before any non-local exposure (raised in Open questions).
