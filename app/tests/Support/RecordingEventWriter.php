<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\EventWriter;

/**
 * In-memory stand-in for EventRepository's write side, used to unit-test RetryScheduler without a
 * live MySQL.
 *
 * The second implementation of EventWriter alongside the real EventRepository (the seam extracted in
 * T3.3). It records each call into the shared CallLog so a test can assert the exact method,
 * arguments and — crucially — that the DB write is logged BEFORE the matching queue write.
 */
final class RecordingEventWriter implements EventWriter
{
    public function __construct(
        private readonly CallLog $log,
    ) {
    }

    public function markForRetry(int $id, int $attempts, string $error): void
    {
        $this->log->record('markForRetry', [$id, $attempts, $error]);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->log->record('markFailed', [$id, $error]);
    }
}
