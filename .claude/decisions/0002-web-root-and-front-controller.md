# 0002. Web root location and front-controller entry point

- **Status:** Proposed
- **Date:** 2026-06-04

## Context
The HTTP side is served by Nginx → PHP-FPM. Today the document root is the whole
application directory: `docker/nginx/conf.d/default.conf:3` sets `root /var/www/html;`,
and `docker-compose.yml:30-31` mounts `./app` to `/var/www/html`. The single entry
file is `app/index.php` (`app/index.php:1-5`), so `GET http://localhost/` already
executes it — the setup "works" right now.

The problem: with `root` at the application directory, **every file under `app/` is
potentially web-served** — `composer.json`, `composer.lock`, `.env`, `.env.example`,
`src/`, `vendor/`, future `migrations/`. Nginx only special-cases `*.php`
(`default.conf:10`); everything else is returned as a static file via the `try_files`
fallback (`default.conf:6-8`). A request for `/.env` or `/composer.lock` would leak
secrets and the dependency surface. `vendor/` also contains executable PHP that must
never be directly reachable.

The spec/tasks already intend a public root: T0.1 names `app/public/index.php`
(`.claude/tasks/webhook-handler.tasks.md:7`). This ADR is about whether to honour
that now or defer, because it requires editing Nginx config (an infra change) and
risks breaking the currently-working URL.

Constraint: this is a learning project for a junior developer; the change must be
small, reversible, and explainable. The worker container does not use Nginx, so it is
unaffected.

## Options considered
### Option A — Keep `index.php` at the app root, `root /var/www/html`
- Pros: zero change; the working URL keeps working; nothing to rebuild.
- Cons: secret/source exposure (`/.env`, `/composer.json`, `/vendor/...`) is a real,
  standing vulnerability; contradicts the spec (`tasks.md:7`); the bad layout gets
  harder to undo once routing (T1.6) and the dashboard (T3.1) are wired to it.

### Option B — Move entry to `app/public/index.php`, set `root /var/www/html/public`
- Pros: only `public/` is web-reachable, so `.env`, `src/`, `vendor/`, `composer.*`
  and `migrations/` sit **above** the document root and cannot be fetched over HTTP;
  matches the spec and standard PHP project layout; `php://input` raw-body reads and
  `vendor/autoload.php` (via `__DIR__ . '/../vendor/autoload.php'`) are unaffected.
- Cons: must edit `default.conf` and recreate the `nginx` container; one path
  indirection for autoload; a stale browser/proxy cache could briefly confuse testing.

### Option C — Keep root at app dir but deny sensitive paths in Nginx
  (`location ~ /\.` , block `composer.*`, `vendor/`, `src/`)
- Pros: no file moves.
- Cons: deny-list security is fragile — every new sensitive artifact needs another
  rule; one missed pattern leaks; still diverges from the spec. An allow-list
  (a dedicated `public/`) is strictly safer than a deny-list.

## Decision
We chose **Option B**. Moving the entry point to `app/public/index.php` and pointing
Nginx `root` at `/var/www/html/public` is the standard, allow-list-by-construction
layout: nothing outside `public/` is reachable over HTTP, which directly satisfies the
NFR that secrets and raw payloads are never exposed (`spec.md:45`). It also aligns the
implementation with the task breakdown (`tasks.md:7`) instead of accruing layout debt
that routing and the dashboard would later depend on. The cost is a two-line Nginx
edit plus `docker compose up -d` for `nginx` — small and reversible.

## Consequences
- Positive: `.env`, `composer.json/.lock`, `src/`, `vendor/`, `migrations/` are no
  longer HTTP-reachable; project matches convention and spec; future routing/dashboard
  build on a correct foundation.
- Negative / debt: an Nginx config change is required and the `nginx` container must be
  recreated (`docker compose up -d nginx`); autoload is referenced one level up
  (`__DIR__ . '/../vendor/autoload.php'`); the old `app/index.php` should be removed to
  avoid two entry points. The env-var naming mismatch (`.env` vs compose) is **not**
  resolved here — it is a T0.2 concern.
