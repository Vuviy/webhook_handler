<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * One unit of work taken off the queue: the typed, consume-side counterpart of the
 * JSON envelope that Queue::enqueue() produces (ADR 0012, Decision 1).
 *
 * It is deliberately the mirror of encodeJob(): the queue's wire schema
 * ({event_id, provider, attempt}) is sealed inside Queue on BOTH sides, so the worker
 * receives this small value object and never touches raw array keys — the same
 * "parse once, hand a typed value to the next layer" shape as WebhookEvent and
 * VerificationResult.
 *
 * A Job is only a REFERENCE: it says which event to fetch, not the event itself. The
 * heavy webhook payload stays in MySQL (webhook_events); the worker reads that row and
 * builds the richer App\Handler\WebhookEvent from it. That is why Job and WebhookEvent
 * overlap in `provider`/`attempt` yet stay separate types — Job is the queue reference,
 * WebhookEvent is the fetched, verified row. Merging them would drag DB columns into the
 * queue layer.
 *
 * `attempt` is carried through unchanged in T2.2: it is NOT incremented or acted on here
 * (no retry yet). Incrementing it and the retry math belong to T2.3.
 */
final readonly class Job
{
    public function __construct(
        private string $eventId,
        private string $provider,
        private int $attempt,
    ) {
    }

    /** The provider's own event id — the key the worker re-reads the row by. */
    public function eventId(): string
    {
        return $this->eventId;
    }

    /** Provider tag: 'github' | 'stripe' | 'paypal'. */
    public function provider(): string
    {
        return $this->provider;
    }

    /** Current delivery attempt, 1-based. */
    public function attempt(): int
    {
        return $this->attempt;
    }
}
