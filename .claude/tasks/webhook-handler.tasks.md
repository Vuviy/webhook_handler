# Tasks: Webhook Handler

Derived from `.claude/specs/webhook-handler.spec.md`. Order matters — top to bottom.
(Implementation is **not** part of the current setup task — this is the plan.)

## Milestone 0: Skeleton & infra
- [x] T0.1 — `composer.json`, PSR-4 (`App\` → `app/src/`), `app/public/index.php` front controller · S ✅ done 2026-06-04 (review: `.claude/reviews/T0.1-2026-06-04.md`, ADR: `0002`, note: `.claude/learning/T0.1-skeleton.md`)
- [x] T0.2 — Config loader reading env (DB, Redis, provider secrets) · S ✅ done 2026-06-04 (review: `.claude/reviews/T0.2-2026-06-04.md`, ADR: `0003`, note: `.claude/learning/T0.2-config.md`)
- [x] T0.3 — DB connection (PDO) + migration runner; `webhook_events` migration · M · depends: T0.2 ✅ done 2026-06-04 (review: `.claude/reviews/T0.3-2026-06-04.md`, ADR: `0004`, note: `.claude/learning/T0.3-db-migrations.md`)
- [x] T0.4 — Redis client wrapper (`Queue`) · S · depends: T0.2 ✅ done 2026-06-04 (review: `.claude/reviews/T0.4-2026-06-04.md`, ADR: `0005`, note: `.claude/learning/T0.4-queue.md`)

## Milestone 1: Ingestion + signatures
- [x] T1.1 — `ProviderVerifier` interface · S ✅ done 2026-06-05 (review: `.claude/reviews/T1.1-2026-06-05.md`, ADR: `0006`, note: `.claude/learning/T1.1-provider-verifier.md`)
- [x] T1.2 — GitHub verifier (HMAC-SHA256, `hash_equals`) · M · depends: T1.1 ✅ done 2026-06-05 (review: `.claude/reviews/T1.2-2026-06-05.md`, ADR: none — direct application of T1.1, note: `.claude/learning/T1.2-github-verifier.md`)
- [x] T1.3 — Stripe verifier (`t`+`v1`, tolerance) · M · depends: T1.1 ✅ done 2026-06-05 (review: `.claude/reviews/T1.3-2026-06-05.md`, ADR: `0007`, note: `.claude/learning/T1.3-stripe-verifier.md`)
- [x] T1.4 — PayPal verifier (verify-webhook-signature API) · L · depends: T1.1 ✅ done 2026-06-05 (review: `.claude/reviews/T1.4-2026-06-05.md`, ADR: `0008`, note: `.claude/learning/T1.4-paypal-verifier.md`)
- [x] T1.5 — `IngestionController` (raw body → verify → dedupe → insert → enqueue → 202) · M · depends: T0.3,T0.4,T1.2 ✅ done 2026-06-05 (review: `.claude/reviews/T1.5-2026-06-05.md`, ADR: `0009`, note: `.claude/learning/T1.5-ingestion-controller.md`)
- [x] T1.6 — Routing `/webhooks/{provider}` + method/`401`/`400` handling · S · depends: T1.5 ✅ done 2026-06-05 (review: `.claude/reviews/T1.6-2026-06-05.md`, ADR: `0010`, note: `.claude/learning/T1.6-routing.md`)

## Milestone 2: Worker, retry, DLQ
- [ ] T2.1 — `HandlerRegistry` + stub per-provider handlers · S
- [ ] T2.2 — `worker.php` loop (`BLPOP` → process → status updates) · M · depends: T0.4,T2.1
- [ ] T2.3 — Exponential backoff + retry scheduler (zset) · M · depends: T2.2
- [ ] T2.4 — DLQ on exhaustion + `last_error` persisted · S · depends: T2.3
- [ ] T2.5 — Graceful shutdown (SIGTERM) · S · depends: T2.2

## Milestone 3: Dashboard & polish
- [ ] T3.1 — Dashboard: counts by status, recent failures, DLQ size · M · depends: T0.3
- [ ] T3.2 — DLQ re-queue path (CLI or button) · S · depends: T2.4
- [ ] T3.3 — Tests for verifiers + retry logic · M

## Definition of done
- [ ] All spec acceptance criteria (AC-1..AC-6) pass
- [ ] Conventions pass (psalm/phpcs if configured)
- [ ] Reviewed by `code-reviewer`; learning note written by `learning-mentor`
