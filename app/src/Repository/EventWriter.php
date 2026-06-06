<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The slice of EventRepository's write surface that the retry POLICY depends on (T3.3 testability
 * seam).
 *
 * RetryScheduler records exactly two state transitions on the audit row — re-arm for another
 * attempt (markForRetry) and terminally fail (markFailed) — and reads nothing back. Extracting
 * them here lets RetryScheduler depend on this abstraction instead of the concrete
 * `final class EventRepository`, so a test can substitute a recording double (no live MySQL) and
 * assert the exact calls and their ORDER relative to the queue writes (the "DB write first" rule).
 * Same shape as the App\Queue\RetryQueue seam and the existing App\Http\HttpClient seam.
 *
 * Deliberately MINIMAL: only the two write methods RetryScheduler uses; all the read/insert SQL on
 * EventRepository stays off this interface. EventRepository remains the sole owner of the
 * webhook_events SQL; this interface only names the methods.
 */
interface EventWriter
{
    /** Re-arm a row for another delivery: back to 'received', bump attempts, store last_error. */
    public function markForRetry(int $id, int $attempts, string $error): void;

    /** Terminally fail a row: status 'failed' with last_error (the DLQ's DB-side counterpart). */
    public function markFailed(int $id, string $error): void;
}
