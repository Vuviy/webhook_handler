---
name: queue-processing
description: Async queue, exponential-backoff retry, dead letter queue and the event log for the webhook handler. Use when implementing or reviewing the worker and processing path.
---

# Queue processing, retry & DLQ

## Why a queue at all
Providers expect a fast `2xx` and will retry on their side if you are slow. So the HTTP
request must **not** do the work. It records the event and pushes a job; a separate
**worker** does the processing. This gives backpressure tolerance and isolation from
slow downstream calls.

## Queue model (Redis)
- Main queue: `webhooks:queue` (Redis list, `RPUSH` to add, `BLPOP` to consume).
- Delayed/retry: `webhooks:retry` (Redis sorted set scored by "ready-at" unix time);
  a scheduler moves due jobs back into the main queue.
- Dead letter: `webhooks:dlq` (list) for jobs that exhausted all retries.
- A job is a small JSON blob: `{ "event_id": ..., "provider": ..., "attempt": 1 }`.
  Keep the heavy payload in the DB, reference it by id.

## Delivery semantics
- This is **at-least-once** delivery: a job can be processed more than once
  (crash after work, before ack). Therefore **handlers must be idempotent** —
  dedupe by the provider event id, use `INSERT ... ON DUPLICATE KEY` / unique constraints.

## Retry with exponential backoff (3 attempts)
- On handler failure, increment `attempt`.
- If `attempt <= 3`: schedule a retry at `now + base * 2^(attempt-1)`
  (e.g. base 2s → 2s, 4s, 8s). Add small jitter to avoid a thundering herd.
- If `attempt > 3`: push to `webhooks:dlq`, set DB `status=failed`.
- Distinguish **permanent** errors (bad data → straight to DLQ, no retry) from
  **transient** ones (timeout, 5xx downstream → retry).

## Event log (MySQL)
`webhook_events` (audit trail + state):
- `id` (PK), `provider`, `event_id` (UNIQUE per provider), `event_type`,
- `payload` (raw), `signature_valid` (bool),
- `status` ENUM(`received`,`processing`,`processed`,`failed`),
- `attempts` (int), `last_error` (text, nullable),
- `created_at`, `updated_at`, `processed_at` (nullable).

Status transitions: `received → processing → processed` (happy path) or
`processing → received (retry scheduled)` … `→ failed (DLQ)`.

## Worker loop (pseudocode)
```
loop:
  job = BLPOP webhooks:queue
  mark event processing
  try:
     handler(provider).handle(event)
     mark event processed
  catch Transient e:
     if job.attempt < 3: schedule_retry(job); mark received
     else:               dlq(job);            mark failed (e)
  catch Permanent e:
     dlq(job); mark failed (e)
```

## Observability / dashboard
Expose read-only counts from `webhook_events` (+ DLQ length): received / processing /
processed / failed, recent failures with `last_error`, and a button-free view is fine.
This satisfies the "monitoring dashboard" success criterion.

## Operational notes
- Run the worker as its own container (`worker` service), `restart: unless-stopped`.
- Make the worker handle `SIGTERM` to finish the current job before exiting (graceful shutdown).
- A DLQ that grows is an alert signal, not a place data goes to die — provide a re-queue path.
