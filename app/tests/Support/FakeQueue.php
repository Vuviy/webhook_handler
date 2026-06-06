<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Queue\RetryQueue;

/**
 * In-memory stand-in for Queue, used to unit-test RetryScheduler without a live Redis.
 *
 * It is the second implementation of RetryQueue alongside the real Queue (the seam extracted in
 * T3.3), and it does nothing but record each call into the shared CallLog so a test can assert the
 * exact method, arguments and ordering. promoteDueRetries returns 0 — RetryScheduler ignores the
 * count, and no retry suite case depends on it.
 */
final class FakeQueue implements RetryQueue
{
    public function __construct(
        private readonly CallLog $log,
    ) {
    }

    public function scheduleRetry(string $eventId, string $provider, int $attempt, int $readyAt): void
    {
        $this->log->record('scheduleRetry', [$eventId, $provider, $attempt, $readyAt]);
    }

    public function deadLetter(string $eventId, string $provider, int $attempt): void
    {
        $this->log->record('deadLetter', [$eventId, $provider, $attempt]);
    }

    public function promoteDueRetries(int $now): int
    {
        $this->log->record('promoteDueRetries', [$now]);

        return 0;
    }
}
