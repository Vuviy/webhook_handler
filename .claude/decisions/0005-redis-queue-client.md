# 0005. Redis queue client (`Queue`) — domain wrapper, not a thin factory

- **Status:** Proposed
- **Date:** 2026-06-04

## Context

T0.4 (`.claude/tasks/webhook-handler.tasks.md:10`) is: *"Redis client wrapper
(`Queue`) · S · depends: T0.2"*. ADR-0001 already chose Redis + a dedicated worker as
transport (`.claude/decisions/0001-redis-queue-and-worker.md:27`); that is settled and not
re-litigated here. This ADR only decides **the shape of the PHP-side client** that the
ingestion controller (T1.5) and later the worker (T2.x) talk to.

Forces and constraints:

1. **Framework-free is a hard rule** (`CLAUDE.md:24`, `.claude/skills/php-conventions/SKILL.md:10`).
2. **The single-implementation rule** — *"Avoid abstractions that have only one
   implementation"* (`SKILL.md:14`). T0.3 honoured this by making `Connection` a thin
   **factory** that returns a raw `\PDO` rather than a delegating wrapper
   (`app/src/Database/Connection.php:12-18`, ADR-0004 lines 73-78). The obvious move is to
   copy that pattern verbatim for Redis. The central question of this ADR is whether that
   copy is correct, or whether `Queue` is the rare case where a wrapper is justified.
3. **The queue has real domain rules that live nowhere else.** The skill fixes the key
   names — `webhooks:queue` (list, `RPUSH`/`BLPOP`), `webhooks:retry` (zset scored by
   ready-at), `webhooks:dlq` (list) — and the **job envelope**: a small JSON
   `{ "event_id", "provider", "attempt" }`, heavy payload stays in the DB
   (`.claude/skills/queue-processing/SKILL.md:14-19`, spec `webhook-handler.spec.md:69`).
   If callers talk to a raw `\Redis`, every call site hardcodes the literal string
   `"webhooks:queue"` and re-implements `json_encode` of the envelope. That is duplicated
   domain knowledge, and a typo in a key name fails silently (writes to the wrong list).
4. **ext-redis (phpredis) is installed; predis is not** (verified: `php -m` lists `redis`;
   `app/composer.json` does not require `predis/predis`). Redis is 7.4-alpine, service
   `redis`, AOF on, env `REDIS_HOST=redis` / `REDIS_PORT=6379`. `App\Config\RedisConfig`
   already exposes `host()` and `port()` and opens no connection
   (`app/src/Config/RedisConfig.php:7-35`).
5. **Scope discipline** (`CLAUDE.md` SOP). T0.4 is sized **S** and depends only on T0.2.
   The worker's consume/retry/DLQ behaviour is T2.2–T2.4. T0.4 must not implement the
   worker loop, the backoff maths, or the retry scheduler.

Convention constraints: `declare(strict_types=1)`, `final`, full type hints, typed
exceptions under `App\Exception\…`, secrets only from env and never logged
(`SKILL.md:9-34`). T0.3 set the sibling patterns to mirror: a static
`Connection::fromConfig(Config)` factory and an `App\Exception\DatabaseException` whose
messages name host/db but never the password (`app/src/Exception/DatabaseException.php:10-25`).

## Options considered

### Option A — Thin factory only (`RedisConnection::fromConfig(): \Redis`), callers use raw `\Redis`
Mirror T0.3 exactly: a factory builds a connected `\Redis`, callers run `rPush`/`blPop`
themselves.
- Pros: maximal consistency with `Connection`; respects the single-implementation rule
  literally; least code.
- Cons: the **domain knowledge has no home**. Each caller hardcodes `"webhooks:queue"` and
  hand-rolls the envelope `json_encode`. A mistyped key name is a silent data-loss bug
  (RPUSH to a non-existent-but-just-created wrong list). The envelope schema cannot evolve
  in one place. This is exactly the duplication the convention is meant to *prevent*, even
  though it satisfies its letter. Rejected.

### Option B — `Queue` domain wrapper around an injected `\Redis` + `fromConfig` factory
A `final class Queue` that **owns the key names and the job-envelope encoding** and exposes
intent-named methods (`enqueue(...)`). It takes a `\Redis` via its constructor (testable —
a fake/mock can be injected) and offers a static `Queue::fromConfig(RedisConfig)` that
builds + connects the `\Redis` and wraps it, mirroring `Connection::fromConfig`.
- Pros: the key names and envelope schema live in exactly one place; call sites express
  intent (`$queue->enqueue($eventId, $provider)`) not Redis primitives; testable via the
  injected client; same `fromConfig` ergonomics as T0.3; connection failure surfaces as a
  typed `QueueException` naming `host:port` only.
- Cons: it is a one-implementation class — but **not** a passthrough: it adds key-name
  ownership + envelope serialization, which is domain behaviour, so it does not fall foul
  of the single-implementation rule (that rule targets delegating wrappers that only forward
  calls). One extra class versus Option A.

### Option C — Option B, but expose the full surface now (`enqueue`, `dequeue`/`BLPOP`, retry-zset, DLQ)
Build every method the system will ever need in T0.4.
- Pros: "finished" client; later tasks just call it.
- Cons: **scope bleed**. `dequeue`/blocking-pop semantics, the retry zset scoring and the
  DLQ move are the substance of T2.2–T2.4 and ADR-worthy decisions of their own (blocking
  timeout, ack model, backoff base/jitter). Designing them now, sized "S", with no worker to
  exercise them, means guessing signatures we will rework. Violates the one-subtask SOP.
  Rejected for now (revisited in T2.x).

## Decision

We chose **Option B** — a `Queue` **domain wrapper** with a narrow surface limited to T0.4's
scope:

- **`Queue` is the sanctioned exception to the single-implementation rule** because it is a
  *domain abstraction*, not a delegating passthrough. It owns (a) the Redis **key names**, so
  the literal `"webhooks:queue"` appears once in the codebase, and (b) the **job-envelope**
  JSON encoding, so the `{event_id, provider, attempt}` schema has a single definition. That
  is real behaviour the raw `\Redis` does not provide. `Connection` stayed a factory because
  a `\PDO` *already* has the full prepared-statement API and a wrapper would only forward;
  `Queue` is different precisely because it adds vocabulary on top of generic commands.

- **Connection strategy mirrors T0.3: eager connect in the factory.** `Queue::fromConfig`
  calls `$redis->connect($host, $port)` immediately and fails fast, so a misconfigured Redis
  is caught at bootstrap, not at the first enqueue mid-request. phpredis `connect()` returns
  `false`/throws on failure; we normalise both into a typed
  `App\Exception\QueueException::connectionFailed($host, $port, $previous)` whose message
  names `host:port` only — never any payload or secret (Redis here has no password, but the
  exception still must never echo job data). New file: `app/src/Exception/QueueException.php`,
  modelled on `DatabaseException` (`app/src/Exception/DatabaseException.php`).

- **Constructor takes a `\Redis`; `fromConfig(RedisConfig)` is the production path.** This
  matches `Connection::fromConfig` ergonomics and keeps `Queue` unit-testable: a test injects
  a fake `\Redis` and asserts the right key + envelope were pushed, with no live server.

- **Surface for T0.4 (and only this):**
  - `public function enqueue(string $eventId, string $provider, int $attempt = 1): void`
    — `json_encode` the envelope and `rPush` it onto `webhooks:queue`. `attempt` defaults to
    1 (first delivery) and is parameterised so the future retry path (T2.3) can re-enqueue
    with a higher number without a second method.
  - Optional, low-cost, read-only: `public function size(): int` (`lLen(webhooks:queue)`) and
    `public function deadLetterSize(): int` (`lLen(webhooks:dlq)`) — pure observability the
    dashboard (T3.1) will want, no consume semantics implied. **Decision: include `size()`
    and `deadLetterSize()`; they are trivial, read-only, and do not pre-judge any T2.x design.**
  - **Deferred to T2.x (explicitly NOT in T0.4):** `dequeue`/blocking-pop, the retry-zset
    push/scan, the DLQ move-on-exhaustion, ack semantics, backoff maths. Key-name constants
    for `webhooks:retry` and `webhooks:dlq` may be declared now (so all three names live in
    `Queue`), but no behaviour beyond the read-only sizes is added for them.

- **Key names are `private const` on `Queue`**: `QUEUE`, `RETRY`, `DLQ` = `'webhooks:queue'`,
  `'webhooks:retry'`, `'webhooks:dlq'`. The envelope is built/encoded by a single private
  helper so its schema has one definition.

- **Encoding policy:** `json_encode(..., JSON_THROW_ON_ERROR)`; on the (near-impossible, all
  scalar) failure, wrap in `QueueException::encodeFailed(...)` rather than pushing a malformed
  job. The envelope only ever contains a string id, a string provider and an int attempt, so
  this is a guard, not a hot path.

## Deviations during implementation

- **`deadLetterSize()` and the `RETRY`/`DLQ` key constants were deferred to T2.x**, contrary to
  the "include them now" decision above. Rationale: `webhooks:dlq` and `webhooks:retry` only
  acquire meaning in the worker tasks (T2.3/T2.4), so a method and constants for them in T0.4
  would be behaviour-free forward references — and unused `private const`s read as dead code to
  the linter. Only `QUEUE`, `enqueue()` and `size()` shipped. `size()` was kept because it
  directly verifies the one path T0.4 delivers (the enqueue). The three key names will be
  co-located in `Queue` when their behaviour lands, preserving the original intent without the
  premature surface.

## Consequences

- Positive: the queue's key names and job-envelope schema have exactly one home, so call
  sites in T1.5 read as intent (`$queue->enqueue($eventId, 'github')`) and a key typo is
  impossible from the outside; `fromConfig` gives the same fail-fast, secret-safe connection
  behaviour as T0.3; the injected-`\Redis` constructor keeps it unit-testable; the narrow
  surface keeps T0.4 honestly "S" and leaves the worker's hard delivery decisions to T2.x
  where they belong.
- Negative / debt: `Queue` is a one-implementation class, a *deliberate, reasoned* exception
  to `SKILL.md:14` (documented above) — the reviewer should check it has not silently grown
  into a passthrough. Eager connect means the PHP-FPM process opens a Redis socket at
  bootstrap (acceptable; mirrors PDO). The retry/DLQ key constants exist before their
  behaviour does — a small, intentional forward reference, justified by keeping all three key
  names co-located; the methods that use them arrive in T2.3/T2.4.
