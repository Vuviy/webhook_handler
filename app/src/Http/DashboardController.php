<?php

declare(strict_types=1);

namespace App\Http;

use App\Queue\Queue;
use App\Repository\EventRepository;

/**
 * The read-only monitoring dashboard (T3.1, FR-10, AC-5): counts by status, the most recent
 * failures, and the dead-letter queue size, returned as one JSON document.
 *
 * Like IngestionController this is PURE: it takes its collaborators by constructor and returns
 * an encoded JSON string; it touches no superglobal and writes no header — the front controller
 * renders the result through the same JSON path every other route uses. That keeps it testable
 * without a live request and keeps the entry point thin (ADR 0010).
 *
 * Two stores, one snapshot: counts/recent-failures come from MySQL (the audit log) and the DLQ
 * size from Redis. They are read sequentially, so the two numbers are a point-in-time snapshot
 * that is only eventually consistent with each other (a job can move between the reads). That is
 * fine for a monitoring view — each number is correct at its own read time (ADR 0016).
 *
 * Errors are NOT handled here on purpose: a DB/Redis outage or a SQL/encode error throws, and the
 * front controller's Throwable backstop turns it into a clean 500 whose body carries no detail
 * (NFR-1). Inventing a local error response here would only risk leaking what the backstop hides.
 */
final class DashboardController
{
    /**
     * How many recent failures to return. Small enough to keep the response tiny and the query
     * cheap, large enough to show the current failure burst at a glance. No ?limit= parameter in
     * T3.1 — the surface stays minimal (ADR 0016).
     */
    private const RECENT_FAILURES_LIMIT = 20;

    public function __construct(
        private readonly EventRepository $events,
        private readonly Queue $queue,
    ) {
    }

    /**
     * Build the dashboard JSON body. Shape (the contract a future static front consumes):
     *
     *   {
     *     "counts":          { "received": N, "processing": N, "processed": N, "failed": N },
     *     "recent_failures": [ { id, provider, event_type, attempts, last_error, created_at, updated_at }, … ],
     *     "dlq_size":        N
     *   }
     *
     * `counts` always has all four enum keys (defaulted to 0 by the repository); `recent_failures`
     * never carries the raw `payload`. JSON_THROW_ON_ERROR turns any encoding fault into a throw
     * that the front-controller backstop maps to 500, rather than emitting a half-formed body.
     */
    public function handle(): string
    {
        $body = [
            'counts' => $this->events->countsByStatus(),
            'recent_failures' => $this->events->recentFailures(self::RECENT_FAILURES_LIMIT),
            'dlq_size' => $this->queue->deadLetterSize(),
        ];

        return json_encode($body, JSON_THROW_ON_ERROR);
    }
}
