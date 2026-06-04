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

## Subtask execution workflow (STANDARD OPERATING PROCEDURE)

**Trigger.** When the user says any of: "do task X", "виконай TX.Y", "go to TX.Y",
"зроби підзадачу …", or names a subtask id from
`.claude/tasks/webhook-handler.tasks.md` — run the FULL pipeline below for that
ONE subtask, without asking the user to restate the rules. Do one subtask per
request, in task-file order, unless the user says otherwise.

**Pipeline (always all four stages, in this order):**

1. **architect** (`backend-architect` sub-agent) — designs the subtask: restate
   the problem, account for the current code state, weigh 2–3 approaches, recommend
   one, and record an **ADR** in `.claude/decisions/NNNN-*.md` *only when there is a
   genuine architectural choice*. The architect does **not** write feature code.
   Resolve its "Open questions" yourself with sensible defaults (state them).
2. **(main agent — me)** — implement the code per the architect's plan, matching
   `.claude/skills/php-conventions/SKILL.md`. Keep strictly to the subtask's scope;
   do not bleed into later subtasks. Then **verify with real commands** (`php -l`,
   `curl`, container CLI, SQL via the db container, etc.) — never fabricate output.
3. **reviewer** (`code-reviewer` sub-agent) — reviews the implementation against the
   spec and conventions; writes a report to `.claude/reviews/<subtask>-<date>.md` with
   BLOCKER/MAJOR/MINOR/NIT severities. It does not fix code. I then apply or
   consciously decline each finding (stating why) before moving on.
4. **mentor** (`learning-mentor` sub-agent) — writes a Ukrainian learning note to
   `.claude/learning/<subtask>.md`.

**After the pipeline:** mark the subtask `[x]` in
`.claude/tasks/webhook-handler.tasks.md` with the date and links to the
review / ADR / learning note.

**Standing rules for every subtask (do not need restating):**
- Communicate with the user in **Ukrainian**.
- **Any SQL query I run against the database MUST be reproduced in the mentor's
  note** (a dedicated "Запити до бази даних" section — and if none were run, say so).
- The **mentor note must explain and show EVERY step I took** (a "Покрокова хроніка"
  section with the actual commands and their output), explain the "why", and show
  alternative approaches with trade-offs.
- The **mentor note must NOT include a self-check / questions section.**
- Verify everything with real commands; report failures honestly.
- Never write application code outside the scope of the requested subtask.

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
