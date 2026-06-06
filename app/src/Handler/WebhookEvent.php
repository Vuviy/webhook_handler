<?php

declare(strict_types=1);

namespace App\Handler;

/**
 * The read-only input a Handler receives: one verified, already-persisted webhook,
 * carried as a value object so the handler is a pure function of its input and never
 * touches the database itself (ADR 0011, Decision 1).
 *
 * The worker (T2.2) is the single producer: it pops the tiny job envelope
 * ({event_id, provider, attempt}) from Redis, reads the matching webhook_events row
 * from MySQL ONCE, and builds this object. Handlers only consume it. That keeps the
 * one DB read — and the one place that must cope with "row vanished" — inside the
 * worker, and leaves the three handlers trivially unit-testable with a hand-built
 * event and no database (relevant to T3.3).
 *
 * This mirrors how App\Verification\VerificationResult carries already-extracted ids
 * so the consumer stays provider-agnostic: the same "parse/read once, hand a typed
 * value to the next layer" shape.
 *
 * `payload` is the RAW request bytes exactly as stored (byte-identical to what was
 * signed) — never re-encoded JSON. A decode helper is deliberately NOT added yet
 * (YAGNI): the stub handlers do not read the body, so the first real handler that
 * needs the decoded shape will introduce decoding where it is actually used.
 *
 * `attempt` is the current delivery attempt (1-based), carried so a future real
 * handler can log it or shape its idempotency without re-deriving it from the queue.
 */
final readonly class WebhookEvent
{
    public function __construct(
        private string $provider,
        private string $eventId,
        private string $eventType,
        private string $payload,
        private int $attempt,
    ) {
    }

    /** Provider tag: 'github' | 'stripe' | 'paypal'. */
    public function provider(): string
    {
        return $this->provider;
    }

    /** The provider's own event id — the dedupe key (UNIQUE (provider, event_id), FR-11). */
    public function eventId(): string
    {
        return $this->eventId;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    /** Raw, byte-exact request body as stored in webhook_events.payload. */
    public function payload(): string
    {
        return $this->payload;
    }

    /** Current delivery attempt, 1-based. */
    public function attempt(): int
    {
        return $this->attempt;
    }
}
