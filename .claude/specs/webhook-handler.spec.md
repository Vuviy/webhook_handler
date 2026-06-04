# Spec: Webhook Handler (GitHub / Stripe / PayPal)

- **Status:** Draft
- **Author:** backend-architect
- **Date:** 2026-06-04
- **Source:** `task.txt`
- **Related ADR:** `.claude/decisions/0001-redis-queue-and-worker.md`

## 1. Problem
We must accept webhooks from **GitHub, Stripe and PayPal**, prove each one is authentic,
record it, and process it reliably even when downstream work is slow or fails. Webhook
providers retry on their side if we are slow or return non-`2xx`, so the HTTP path must be
fast and the real processing must be asynchronous, retried and auditable.

## 2. Goals
- Authentic-only ingestion: every accepted webhook has a verified signature.
- Fast acknowledgement: respond `2xx` quickly; do the work later.
- Reliable processing: retry transient failures, isolate poison messages.
- Full auditability: every webhook and its outcome is in the database.
- Operability: a dashboard to see throughput and failures.

## 3. Non-goals
- Acting on the business meaning of each event (e.g. provisioning on Stripe payment) —
  out of scope beyond a pluggable handler stub.
- Multi-tenant secret management / a secret-rotation UI.
- Horizontal autoscaling of workers (single worker container is enough for now).

## 4. Functional requirements
- **FR-1** `POST /webhooks/github` — verify `X-Hub-Signature-256` (HMAC-SHA256 over raw body).
- **FR-2** `POST /webhooks/stripe` — verify `Stripe-Signature` (`t`+`v1`, HMAC-SHA256) with
  a timestamp tolerance (replay protection).
- **FR-3** `POST /webhooks/paypal` — verify via PayPal's `verify-webhook-signature`
  (or cert-based) scheme.
- **FR-4** On valid signature: insert an event row (`status=received`) and enqueue a job.
- **FR-5** On invalid/missing signature: respond `401`, do **not** enqueue.
- **FR-6** Worker consumes the queue and dispatches to a per-provider handler.
- **FR-7** Retry failed processing with exponential backoff, max **3 attempts**.
- **FR-8** After 3 failed attempts: move the job to a **Dead Letter Queue**, mark `failed`.
- **FR-9** Persist a full event log (provider, type, payload, signature result, status, attempts, error, timestamps).
- **FR-10** Provide a read-only **monitoring dashboard** (counts by status, recent failures, DLQ size).
- **FR-11** Deduplicate by provider event id (idempotent re-delivery returns `2xx`, no double-processing).

## 5. Non-functional requirements
- **NFR-1 (security)** Signatures verified over the raw bytes; compares are timing-safe
  (`hash_equals`); secrets only from env; secrets/raw payloads never logged.
- **NFR-2 (reliability)** At-least-once delivery; handlers idempotent; transient vs permanent
  errors distinguished.
- **NFR-3 (latency)** HTTP ingestion does no downstream business calls; target ack < 200ms.
- **NFR-4 (observability)** Every state transition recorded; DLQ growth is visible.

## 6. Design
### Endpoints
`POST /webhooks/{github|stripe|paypal}` → ingestion controller (thin).

### Flow (HTTP)
read raw body → select provider verifier → verify → dedupe by event id →
insert `webhook_events (status=received)` → `RPUSH webhooks:queue` → `202 Accepted`.

### Flow (worker)
`BLPOP webhooks:queue` → `status=processing` → provider handler →
success `status=processed` | transient fail → schedule retry (`now + 2^(n-1)·base`, jitter),
`attempts++` | attempts > 3 or permanent → `webhooks:dlq`, `status=failed`.

### Data model — `webhook_events`
`id` PK · `provider` · `event_id` (UNIQUE per provider) · `event_type` · `payload` (raw) ·
`signature_valid` bool · `status` ENUM(received,processing,processed,failed) ·
`attempts` int · `last_error` text? · `created_at` · `updated_at` · `processed_at?`.

### Redis keys
`webhooks:queue` (list) · `webhooks:retry` (zset, score = ready-at) · `webhooks:dlq` (list).

### Components
`ProviderVerifier` (interface + 3 impls) · `IngestionController` · `EventRepository` ·
`Queue` (Redis) · `RetryScheduler` · `Worker` · `HandlerRegistry` · `DashboardController`.

## 7. Acceptance criteria
- [ ] **AC-1** A request with a valid signature is accepted (`202`) and logged as `received`;
      an invalid one is rejected (`401`) and not enqueued. *(maps task ✓ "Signatures are verified")*
- [ ] **AC-2** Enqueued events are picked up and processed by the worker.
      *(✓ "Queue processing is working")*
- [ ] **AC-3** A handler that fails transiently is retried up to 3 times with growing delays.
      *(✓ "Retry logic is working")*
- [ ] **AC-4** After 3 failures the event lands in the DLQ and is marked `failed` with the error.
      *(✓ "Failed webhooks are logged")*
- [ ] **AC-5** The dashboard shows counts by status, recent failures and DLQ size.
      *(✓ "Monitoring dashboard")*
- [ ] **AC-6** Re-delivering the same event id does not double-process and still returns `2xx`.

## 8. Risks & edge cases
- Empty body / missing signature header / malformed JSON → `400`/`401`, never `5xx`.
- Replay attacks → enforce timestamp tolerance (Stripe), dedupe by event id.
- Poison message that always throws → DLQ, must not block the queue.
- Thundering herd on retries → add jitter to backoff.
- Worker killed mid-job → at-least-once means possible reprocess → idempotency required.
- Secret rotation → support reading current + previous secret during a window (future).

## 9. Open questions
- PayPal verification: server-side API call vs local certificate crypto — which first?
- Backoff base/unit: seconds (2/4/8s) or minutes? Depends on expected downstream recovery time.
- Dashboard: server-rendered PHP page vs a small JSON API + static front — decide scope.
- DLQ re-queue: manual (phpMyAdmin/CLI) vs a button in the dashboard.
