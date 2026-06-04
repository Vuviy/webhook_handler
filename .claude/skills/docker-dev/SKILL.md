---
name: docker-dev
description: How the local Docker Compose environment for the webhook handler is wired and how to run it. Use for environment, build and run questions.
---

# Docker dev environment

## Services (`docker-compose.yml`)
- `php` — PHP 8.3-FPM, serves HTTP ingestion. Mounts `./app`.
- `nginx` — front-end on `:80`, forwards `*.php` to `php:9000`.
- `worker` — same PHP image, runs `php worker.php` to consume the Redis queue.
- `db_webhook` — MySQL 8.4, persistent volume `mysql_data`.
- `redis` — Redis 7.4, queue + retry + DLQ, AOF persistence, volume `redis_data`.
- `phpmyadmin` — DB inspection on `:8000`.

## Environment
`.env` (local, git-ignored) provides `DB_DATABASE` and `MYSQL_ROOT_PASSWORD`.
Compose injects DB + Redis connection vars into `php` and `worker`.
Copy `.env.example` → `.env` before first run.

## Commands
```bash
docker compose up -d --build      # build & start everything
docker compose ps                 # status
docker compose logs -f worker     # follow the worker
docker compose logs -f php nginx  # follow the HTTP side
docker compose exec php bash      # shell into PHP
docker compose exec php composer install
docker compose down               # stop (add -v to wipe volumes)
```

## Endpoints
- Webhooks: `POST http://localhost/webhooks/{github|stripe|paypal}`
- Dashboard: `http://localhost/`
- phpMyAdmin: `http://localhost:8000`

## Notes
- The `worker` will crash-loop until `app/worker.php` exists — expected before implementation.
- The PHP image already includes `redis` (pecl), `pdo_mysql`, `bcmath`, `intl`, `opcache`.
- Testing a provider locally: send a raw `POST` with the right signature header,
  or use the provider's CLI (e.g. `stripe listen --forward-to localhost/webhooks/stripe`).
