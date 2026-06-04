# 0001. Use Redis + a dedicated worker for async webhook processing

- **Status:** Accepted
- **Date:** 2026-06-04

## Context
Webhook providers (GitHub, Stripe, PayPal) expect a fast `2xx` and retry on their side if we
are slow or fail. We need asynchronous processing, retry with backoff, a dead letter queue and
an audit log. The stack is framework-free PHP 8.3 with MySQL already present, and Docker Compose
for local dev.

## Options considered
### Option A — MySQL as the queue (polling a `jobs` table)
- Pros: no new infrastructure; transactional with the event log; simple mental model.
- Cons: polling latency or busy-waiting; row-locking contention at volume; no native
  blocking-pop or delayed-set; reinvents a broker.

### Option B — Redis (list + sorted set) with a dedicated worker container
- Pros: `BLPOP` gives low-latency blocking consume; sorted set is a natural delayed-retry
  store; DLQ is just another list; PHP `redis` ext already in the image; cheap and standard.
- Cons: a second datastore to run; at-least-once semantics push idempotency onto handlers.

### Option C — A full broker (RabbitMQ / Kafka)
- Pros: mature delivery guarantees, routing, DLX built in.
- Cons: heavy for this scope; operational overhead; overkill for 3 providers and a learning project.

## Decision
We chose **Option B (Redis + dedicated worker)**. It matches the reliability requirements
(blocking consume, delayed retry, DLQ) with minimal new moving parts, the Redis PHP extension
is already installed, and it teaches the core patterns (at-least-once, idempotency, backoff,
DLQ) that generalise to bigger brokers later. MySQL remains the **system of record** (event log);
Redis is only transport.

## Consequences
- Positive: fast ack, isolated processing, simple retry/DLQ, clear separation of record (MySQL)
  vs transport (Redis).
- Negative / debt: at-least-once delivery forces **idempotent handlers** (dedupe by event id);
  Redis is not durable to the same degree as MySQL (mitigated with AOF); a separate `worker`
  container must be operated and monitored.
