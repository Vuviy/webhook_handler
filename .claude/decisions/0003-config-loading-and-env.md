# 0003. Config loading strategy and environment variable contract

- **Status:** Proposed
- **Date:** 2026-06-04

## Context
T0.2 (`.claude/tasks/webhook-handler.tasks.md:8`) is: *"Config loader reading env
(DB, Redis, provider secrets)"*. Scope is **only** reading and structuring config at
bootstrap and exposing typed, validated values. Opening PDO (T0.3), creating the Redis
client (T0.4), routing (T1.6) and verifiers/worker are explicitly out of scope. T0.2
must **not** connect to anything.

Three forces make this non-trivial:

1. **Two env layers with different consumers.**
   - Root `/.env` (`DB_DATABASE=db_webhook`, `MYSQL_ROOT_PASSWORD=root`) is read by
     **docker-compose itself** for `${...}` substitution (`docker-compose.yml:14,16,53,66`).
   - Compose then injects **real process env vars** into the `php` and `worker`
     containers: `DB_HOST=db_webhook`, `DB_DATABASE`, `DB_USERNAME=root`, `DB_PASSWORD`,
     `REDIS_HOST=redis`, `REDIS_PORT=6379` (`docker-compose.yml:12-18,49-55`).
   - The PHP app additionally has `app/.env` (read via `vlucas/phpdotenv ^5.6`,
     installed in `app/vendor/`).

2. **Naming mismatch.** `app/.env` (`app/.env:1-5`) uses `DB_HOST, DB_NAME, DB_USER,
   DB_PASS, SQL_DRIVER`. Compose injects `DB_DATABASE, DB_USERNAME, DB_PASSWORD`. These
   do **not** match, so an app that reads `DB_DATABASE` would find nothing in `app/.env`,
   and an app that reads `DB_NAME` would find nothing in the container env. One naming
   contract must win.

3. **Provider secrets are not injected by compose.** Root `.env.example:8-19` lists
   `GITHUB_WEBHOOK_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYPAL_WEBHOOK_ID`,
   `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, but the `php`/`worker` `environment:`
   blocks (`docker-compose.yml:12-18,49-55`) do **not** pass them into the containers.
   So the connection vars arrive as process env, but the secrets must arrive another way.

phpdotenv ^5.6 facts that constrain the choice (confirmed in `app/vendor/vlucas/
phpdotenv/src/Dotenv.php:112,220,234,251` and `Validator.php:49,66`):
- `createImmutable()` builds an **immutable** repository: it will **not overwrite** an
  env var that already exists in the process environment.
- `safeLoad()` does **not throw** if the `.env` file is missing (unlike `load()`).
- `->required([...])->notEmpty()` provides fail-fast validation at bootstrap.

Convention constraints: `declare(strict_types=1)`, `final`, full type hints, typed
exceptions under `App\Exception\...`, secrets only from env and **never logged**
(`.claude/skills/php-conventions/SKILL.md:9-35`, `webhook-security/SKILL.md:42`).

## Options considered

### Decision 1 — Loader mode & precedence

#### Option A — `createImmutable` + `safeLoad`, process-env-first, `app/.env` as fallback
- Load via `Dotenv::createImmutable($appDir)->safeLoad()`.
- Inside containers the compose-injected vars already exist, so immutability keeps
  them authoritative; `app/.env` only **fills gaps** (the provider secrets, and any
  var compose does not inject). Locally (no container), there is no injected env, so
  `app/.env` supplies everything. Missing file does not crash bootstrap.
- Pros: one code path works identically in-container and on a bare host; production
  injection always wins over a possibly-stale `app/.env`; matches the
  "12-factor / env is the source of truth" model; resilient when `app/.env` is absent.
- Cons: a developer who edits `app/.env` expecting it to override a container var will
  be surprised (immutability ignores it) — must be documented.

#### Option B — `createMutable` + `load`, dotenv-first (file overrides process env)
- Pros: editing `app/.env` always wins, which can feel intuitive locally.
- Cons: `app/.env` would silently override compose-injected production values — exactly
  the wrong precedence for a deployed system; `load()` throws if the file is missing,
  making bootstrap brittle; mutable mode calls `putenv()`, leaking values into
  `getenv()` for the whole process (larger secret surface). Rejected.

#### Option C — No phpdotenv; read `getenv()` only, inject everything via compose
- Pros: simplest mental model; no file precedence question.
- Cons: requires adding all five provider secrets to **both** `php` and `worker`
  `environment:` blocks (and to local shells), abandoning the already-installed
  phpdotenv and the existing `app/.env`. More infra churn for a learning project and
  no place to keep local secrets out of compose. Rejected for now (kept as a future
  production option — see Consequences).

### Decision 2 — Config representation

#### Option D — Typed readonly value objects (recommended)
- A `final readonly App\Config\Config` aggregate built once, holding small readonly
  sub-objects: `DatabaseConfig`, `RedisConfig`, `ProviderSecrets`.
- Pros: type-safe getters (`->database()->name(): string`), fail-fast at construction
  if a required key is missing, secrets are encapsulated (easier to keep out of logs,
  e.g. no array dump), aligns with conventions (`final`, readonly, full types).
- Cons: a little more code than a bag; adding a key touches the VO.

#### Option E — Flat key bag `Config::get(string $key): ?string`
- Pros: minimal code; trivial to add keys.
- Cons: stringly-typed, no compile-time safety, returns `null` for typos that surface
  far away as cryptic errors, invites `var_dump($config)` that prints secrets. Rejected.

## Decision

**Decision 1: Option A** — `Dotenv::createImmutable($appDir)->safeLoad()`, with
**precedence: process env (compose-injected) first, `app/.env` as fallback**. This
keeps deployed/injected connection vars authoritative, lets `app/.env` carry the
provider secrets that compose does not inject, and survives a missing file. Both the
`php` and `worker` containers mount `./app` (`docker-compose.yml:10-11,47-48`), so a
single `app/.env` serves both entry points — no duplication.

**Decision 2: Option D** — typed readonly value objects, with explicit
required-vs-optional rules and fail-fast validation in the factory, throwing a typed
`App\Exception\ConfigException` rather than letting a `null` surface later in T0.3/T0.4.

**Naming contract:** standardise `app/.env` and `app/.env.example` on the
**compose names** (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DRIVER`,
`REDIS_HOST`, `REDIS_PORT`) plus the five provider secret keys. Aligning the file to
compose (rather than the reverse) means the in-container process env and the local file
use **one** vocabulary, so the loader reads the same key names in both environments.
`SQL_DRIVER` is renamed to `DB_DRIVER` for prefix consistency.

## Consequences
- Positive: one loader path for container and host; injected production values always
  win; provider secrets live in the git-ignored `app/.env` and reach both `php` and
  `worker` via the shared mount; required-key validation fails loudly at bootstrap with
  a typed exception; secrets stay encapsulated in value objects (no accidental dumps).
- Negative / debt: `app/.env` overriding a compose-injected var is intentionally
  **not** possible (immutable) — this must be documented so it is not mistaken for a
  bug. The provider secrets being file-only (not compose-injected) means a future
  production deployment without this file would need them injected another way; this is
  acceptable now and recorded as a future option (Decision-1 Option C). The connection
  vars (`DB_*`, `REDIS_*`) are treated as **required**; provider secrets are **optional
  at bootstrap** (a verifier that needs an empty secret fails later in its own task),
  so that the app can boot and serve `/` health before any provider is configured.
