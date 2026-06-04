# CLAUDE.md

This file guides Claude Code (and the project's sub-agents) when working in this
repository. Read it before doing anything.

## What this project is

**Webhook Handler** — a system that receives, verifies and processes incoming
webhooks from **GitHub**, **Stripe** and **PayPal**.

Core capabilities (see `task.txt` and `.claude/specs/`):

- Signature verification per provider (HMAC-SHA256 for GitHub, Stripe scheme `v1`,
  PayPal certificate / verify-webhook-signature API).
- Fast HTTP ingestion: verify → persist raw event → enqueue → return `2xx` quickly.
- Asynchronous processing through a **queue** (Redis).
- **Retry** with exponential backoff (3 attempts).
- **Dead Letter Queue** for permanently failed events.
- Event **log in the database** (audit trail of every webhook).
- A **monitoring dashboard** (counts, failures, DLQ contents).

## Tech stack

- PHP 8.3 (FPM) — **framework-free** (hand-rolled, no Laravel/Symfony).
- MySQL 8.4 — event log + state.
- Redis — queue, retry (delayed) and dead-letter storage.
- Nginx — HTTP front for the ingestion endpoints.
- A long-running **worker** process — consumes the queue.
- Docker Compose — local environment.

## Architecture (intended target — not yet implemented)

```
GitHub / Stripe / PayPal
        │  POST /webhooks/{provider}
        ▼
   Nginx ──► PHP-FPM (Ingestion controller)
        │  1. read RAW body
        │  2. verify signature (timing-safe)
        │  3. INSERT event (status=received) into MySQL
        │  4. RPUSH job to Redis queue
        │  5. return 202 Accepted
        ▼
   Redis queue ──► Worker (long-running)
        │  - pop job
        │  - dispatch to provider handler
        │  - success → status=processed
        │  - failure → retry (backoff 2^n), attempts++ 
        │  - 3 failures → Dead Letter Queue, status=failed
        ▼
   MySQL  (full audit log)  +  Dashboard (read-only view)
```

Key design rule: **the HTTP request must never do the heavy work.** It only
verifies, records and enqueues. All real processing happens in the worker. This
is what makes the system resilient and the providers happy (they expect a fast
`2xx`, otherwise they retry on their side).

## Repository layout

- `app/` — the PHP application code (currently empty; **to be implemented**).
- `docker/` — PHP & Nginx images and config.
- `docker-compose.yml` — services: `php`, `nginx`, `worker`, `db_webhook`, `redis`, `phpmyadmin`.
- `task.txt` — the original task statement.
- `.claude/specs/` — the formal specification derived from the task.
- `.claude/agents/` — sub-agents: `architect`, `reviewer`, `mentor`.
- `.claude/skills/` — reusable knowledge: conventions, webhook security, queue processing, docker.
- `.claude/templates/` — templates the agents fill in (spec / tasks / ADR / review / learning).
- `.claude/decisions/` — ADRs (architecture decision records).
- `.claude/learning/` — learning notes produced after a feature.
- `НАВЧАННЯ.md` — a detailed, Ukrainian-language walkthrough of the whole task (for training).

## Learning Mode (IMPORTANT)

The owner of this repo is a **junior PHP developer growing toward middle level**
and uses this project deliberately for learning. Therefore:

- **Explain the "why", not only the "what".** Tie decisions to general principles
  (security, idempotency, at-least-once delivery, backpressure, HTTP semantics).
- Prefer clarity over cleverness; avoid unnecessary abstractions.
- When relevant, mention the alternative approaches that were rejected and why.
- Communicate in **Ukrainian** when explaining or mentoring.

## Conventions

- `declare(strict_types=1);` in every PHP file.
- Full type hints; `final` classes by default.
- PSR-12 formatting; PSR-4 autoloading.
- Always read the **raw** request body for signature checks (never re-encoded JSON).
- Always compare signatures with `hash_equals()` (timing-safe), never `==`.
- Secrets only via environment variables — never hardcoded, never logged.
- Treat delivery as **at-least-once**: handlers must be **idempotent**
  (dedupe by provider event id).

## Workflow with sub-agents

1. **architect** — turns a task into a spec, a task breakdown and an ADR. No code.
2. (main agent) — implements according to the approved spec.
3. **reviewer** — reviews the diff against the spec & conventions. No fixes, only a report.
4. **mentor** — writes a Ukrainian learning note explaining the finished feature.

## Common commands

```bash
docker compose up -d --build      # start the environment
docker compose ps                 # service status
docker compose logs -f worker     # watch the queue worker
docker compose exec php bash      # shell into the PHP container
docker compose exec php composer install
```

Dashboard: http://localhost/  · phpMyAdmin: http://localhost:8000

## Hard rules for Claude in this repo

- **Do not write application code unless explicitly asked.** The current task is
  setup, specs and documentation only.
- Never invent results (e.g. tool output, test runs). If something can't be run,
  say so.
- Keep secrets out of the repo; `.env` is local only.
