# 0004. PDO connection and migration runner strategy

- **Status:** Proposed
- **Date:** 2026-06-04

## Context

T0.3 (`.claude/tasks/webhook-handler.tasks.md:9`) is: *"DB connection (PDO) + migration
runner; `webhook_events` migration · depends: T0.2"*. Scope is only: build a PDO from the
T0.2 config, provide a CLI migration runner that applies plain `.sql` files in order and
records what ran, and ship the first migration (`webhook_events`). Redis (T0.4), the
ingestion controller / repository (T1.5), routing (T1.6) and the worker are out of scope —
no business reads/writes, just connect + schema.

Constraints and forces that make the runner non-trivial:

1. **Framework-free is a hard rule** (`CLAUDE.md:24`, `.claude/skills/php-conventions/SKILL.md:10`).
   No Laravel/Symfony; migrations are defined as *"plain `.sql` files under `app/migrations/`,
   applied in order"* (`SKILL.md:35`). `app/migrations/` does not exist yet.
2. **MySQL DDL is not transactional.** MySQL 8.4 performs an implicit commit before and after
   each DDL statement (`CREATE TABLE`, …). Wrapping a migration in `BEGIN…COMMIT` cannot roll
   back a half-applied DDL, so transaction-based "all or nothing" safety is an illusion here.
3. **Re-run safety is the core correctness property.** A second `migrate` invocation must be a
   genuine no-op — the migration analogue of the at-least-once / idempotency rule that governs
   the whole webhook system (`webhook-security/SKILL.md:37`, `queue-processing/SKILL.md:22`).
4. **Audit ethos.** The project is built around a full event log; a migration mechanism with a
   recorded history fits that mindset better than a stateless re-apply.
5. **No migration library is installed.** `app/composer.json` declares only `phpdotenv`,
   `otphp`, `php-jwt` and the pdo/redis/openssl extensions — no `doctrine/migrations` or
   `robmorgan/phinx`.

Convention constraints: `declare(strict_types=1)`, `final`, full type hints, typed exceptions
under `App\Exception\…`, PDO with prepared statements only, secrets (DB password) never logged
(`SKILL.md:9-34`, `webhook-security/SKILL.md:42`). `DatabaseConfig` already exposes a `dsn()`
(`app/src/Config/DatabaseConfig.php:60`) and a `password()` marked "do not log"
(`DatabaseConfig.php:48`).

## Options considered

### Option A — Custom `.sql` runner + `schema_migrations` tracking table
- Read `app/migrations/*.sql` sorted by filename; ensure a `schema_migrations` table;
  select already-applied filenames; apply only the new ones in order; insert a row per file.
- Pros: framework-free; forward-only history fits the audit ethos; re-run is a data-driven
  no-op (not an `IF NOT EXISTS` guess); deterministic ordering via numeric filename prefixes;
  simple enough to teach (learning mode, `CLAUDE.md:73`); reusable base for every later schema
  change.
- Cons: ~60 lines we own and must get right (file ordering, error reporting, multi-statement
  policy).

### Option B — Single idempotent `schema.sql` with `CREATE TABLE IF NOT EXISTS`
- One file, re-applied wholesale each run.
- Pros: minimal code; `IF NOT EXISTS` makes a re-run safe.
- Cons: no record of what ran when (weak for an audit-centric system); `IF NOT EXISTS`
  silently skips a table whose schema has **drifted**, so a later column addition to an
  existing table is missed entirely — a real foot-gun; does not express ordered, incremental
  change. Rejected.

### Option C — Pull in a migration library (doctrine/migrations or phinx)
- Pros: mature, handles edge cases, up/down, status command.
- Cons: none is installed (`app/composer.json`); adding one violates the framework-free hard
  rule (`CLAUDE.md:24`) and the learning goal of understanding the mechanics; Doctrine
  migrations also drags in DBAL for a project that deliberately hand-rolls PDO. Rejected.

## Decision

We chose **Option A — a custom `.sql` runner with a `schema_migrations` tracking table**,
because it is the only option that is simultaneously framework-free, forward-history-aware
(matching the project's audit ethos), re-run-safe by recorded data rather than by guessing,
and a reusable foundation for later migrations.

Supporting decisions:

- **PDO is a thin factory, not a wrapper.** `App\Database\Connection::fromConfig(DatabaseConfig)`
  returns a configured `\PDO` (no delegating wrapper — `SKILL.md:14` forbids one-implementation
  abstractions). Attributes: `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false` (real prepared
  statements), `DEFAULT_FETCH_MODE=FETCH_ASSOC`, non-persistent, eager connect. On
  `PDOException` it rethrows `App\Exception\DatabaseException` whose message names host + db
  but never the password or DSN credentials.
- **Non-transactional DDL is handled by design, not by faking transactions.** The runner does
  not wrap each file in `BEGIN…COMMIT`. Instead: it records the `schema_migrations` row only
  *after* the DDL succeeds (a crash before recording leaves the file pending and retried), and
  each migration uses idempotent DDL (`CREATE TABLE IF NOT EXISTS`) so a retry after a partial
  apply does not hard-fail.
- **One statement per file** is the standing convention; the runner executes each file via
  `$pdo->exec()` and does not implement a `;`-splitter (a correct one must respect semicolons
  inside strings/comments — needless complexity). A future multi-statement change is split into
  separate numbered files instead.
- **Filenames are the migration identity**, zero-padded numeric prefix + snake description
  (`0001_create_webhook_events.sql`), ordered by `glob()+sort()`. The first migration creates
  `webhook_events` with a composite `UNIQUE (provider, event_id)` (the dedup/idempotency
  backbone, `webhook-security/SKILL.md:39`), `idx_status` and `idx_created_at` for the
  dashboard, InnoDB / utf8mb4, and DB-managed `updated_at` via `ON UPDATE CURRENT_TIMESTAMP`.
- **CLI invocation:** `docker compose exec php php bin/migrate.php`; prints one line per applied
  file, exits `0` on success and `1` (with a typed `DatabaseException` to STDERR) on failure.

## Consequences

- Positive: re-runs are clean no-ops driven by `schema_migrations`; migration history is
  auditable; the runner is framework-free and reusable for all later schema changes; the PDO
  factory gives real prepared statements and loud failures while keeping the password out of
  logs; the `webhook_events` unique key enables `INSERT … ON DUPLICATE KEY` dedup in T1.5.
- Negative / debt: we own and must test the runner; because MySQL DDL auto-commits, a migration
  file containing several statements could partially apply on crash — mitigated by the
  one-statement-per-file convention and idempotent DDL, but it remains a real constraint to
  respect. No `down`/rollback path is provided (forward-only); reverting a bad migration means
  writing a new one. The `schema_migrations` bootstrap table is the single sanctioned use of
  `CREATE TABLE IF NOT EXISTS` outside a numbered migration.
```