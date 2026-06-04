---
name: php-conventions
description: Coding conventions for this framework-free PHP 8.3 webhook handler. Use whenever writing or reviewing PHP in app/.
---

# PHP conventions

## Language & style
- First line of every PHP file: `declare(strict_types=1);`.
- PHP 8.3, **no framework**. Composer + PSR-4 autoloading (`App\` → `app/src/`).
- PSR-12 formatting. Classes `final` by default; make non-final only with a reason.
- Full type declarations on every parameter, property and return type.
- Prefer constructor property promotion and readonly properties.
- Small classes, single responsibility. Avoid abstractions that have only one implementation.

## Errors & values
- Throw typed exceptions (`App\Exception\...`); never `throw new \Exception('...')` bare.
- No silent `@` error suppression.
- Validate input at the boundary (controller); inside the domain assume validated data.

## HTTP / webhooks specifics
- Read the **raw** request body once (`file_get_contents('php://input')`) and reuse it.
  Never run signature checks against a re-encoded array.
- Return early with the right status: `202 Accepted` on enqueue, `400` bad payload,
  `401` bad signature, `405` wrong method.
- Endpoints stay thin: verify → persist → enqueue → respond. No business logic here.

## Security
- Compare digests with `hash_equals($expected, $actual)` — constant time.
- Secrets only from `getenv()` / `$_ENV`. Never hardcode, never log a secret or a full payload
  that may contain one.

## Database
- PDO with prepared statements only — no string-concatenated SQL.
- Migrations are plain `.sql` files under `app/migrations/`, applied in order.

## Tooling (if configured)
```bash
docker compose exec php composer install
docker compose exec php vendor/bin/phpcs app/
docker compose exec php vendor/bin/psalm
```
