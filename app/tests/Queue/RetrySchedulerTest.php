<?php

declare(strict_types=1);

namespace App\Tests\Queue;

use App\Queue\Job;
use App\Queue\RetryScheduler;
use App\Tests\Support\CallLog;
use App\Tests\Support\FakeQueue;
use App\Tests\Support\RecordingEventWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the retry POLICY (FR-7): the exponential-backoff range, the "DB write before queue write"
 * ordering, and the attempt-limit → DLQ transition. The collaborators are in-memory doubles reached
 * through the RetryQueue / EventWriter seams, so the suite asserts the exact calls and their
 * order with no Redis and no MySQL. Jitter is random, so backoff is asserted by RANGE, never an
 * exact value (NFR-2).
 */
#[CoversClass(RetryScheduler::class)]
final class RetrySchedulerTest extends TestCase
{
    private CallLog $log;
    private RetryScheduler $scheduler;
    private RecordingEventWriter $events;

    protected function setUp(): void
    {
        $this->log = new CallLog();
        $this->scheduler = new RetryScheduler(new FakeQueue($this->log));
        $this->events = new RecordingEventWriter($this->log);
    }

    /**
     * Equal jitter: for attempt n the nominal delay is D = 2^(n-1)·2s and the actual delay is
     * D/2 + rand(0, D/2), i.e. it always lands in [D/2, D]. Asserted over many iterations because
     * the jitter is random.
     *
     * @return iterable<string, array{int, int, int}> [attempt, lowerBound, upperBound] seconds
     */
    public static function backoffRanges(): iterable
    {
        yield 'attempt 1' => [1, 1, 2];
        yield 'attempt 2' => [2, 2, 4];
        yield 'attempt 3' => [3, 4, 8];
    }

    #[DataProvider('backoffRanges')]
    public function testDelayForStaysWithinEqualJitterRange(int $attempt, int $low, int $high): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $delay = $this->scheduler->delayFor($attempt);

            self::assertGreaterThanOrEqual($low, $delay);
            self::assertLessThanOrEqual($high, $delay);
        }
    }

    #[DataProvider('backoffRanges')]
    public function testDelayForActuallyVariesAndReachesBothBounds(int $attempt, int $low, int $high): void
    {
        // Guards against a "stuck" implementation that always returns the same value and would still
        // pass the range test. Over many draws the equal-jitter delay must hit both its min and max.
        $min = PHP_INT_MAX;
        $max = PHP_INT_MIN;

        for ($i = 0; $i < 1000; $i++) {
            $delay = $this->scheduler->delayFor($attempt);
            $min = min($min, $delay);
            $max = max($max, $delay);
        }

        self::assertSame($low, $min);
        self::assertSame($high, $max);
    }

    public function testRetryOrFailWhileAttemptsRemainSchedulesARetryDbBeforeQueue(): void
    {
        $before = time();
        $this->scheduler->retryOrFail($this->events, 42, new Job('evt_1', 'stripe', 1), 'handler 500');
        $after = time();

        // DB write is recorded BEFORE the queue write (the crash-safety ordering).
        self::assertSame(['markForRetry', 'scheduleRetry'], $this->log->methods());

        // markForRetry(id, attempt+1, error)
        self::assertSame([42, 2, 'handler 500'], $this->log->argsFor('markForRetry'));

        // scheduleRetry(eventId, provider, attempt+1, readyAt) with readyAt = now + delayFor(2),
        // and delayFor(2) ∈ [2, 4]; bound it against the time window the call ran in.
        [$eventId, $provider, $attempt, $readyAt] = $this->log->argsFor('scheduleRetry');
        self::assertSame('evt_1', $eventId);
        self::assertSame('stripe', $provider);
        self::assertSame(2, $attempt);
        self::assertGreaterThanOrEqual($before + 2, $readyAt);
        self::assertLessThanOrEqual($after + 4, $readyAt);
    }

    public function testRetryOrFailOnLastRemainingAttemptStillSchedules(): void
    {
        // attempt 2 → next 3, still <= MAX_ATTEMPTS(3): a retry, not a dead-letter.
        $this->scheduler->retryOrFail($this->events, 7, new Job('evt_2', 'github', 2), 'boom');

        self::assertSame(['markForRetry', 'scheduleRetry'], $this->log->methods());
        self::assertSame([7, 3, 'boom'], $this->log->argsFor('markForRetry'));
        self::assertSame(3, $this->log->argsFor('scheduleRetry')[2]);
    }

    public function testRetryOrFailAtExhaustionDeadLettersAndDoesNotSchedule(): void
    {
        // attempt 3 → next 4 > MAX_ATTEMPTS(3): exhausted. Mark failed (DB) THEN push to DLQ,
        // carrying the LAST attempt that ran (3). No retry is scheduled.
        $this->scheduler->retryOrFail($this->events, 9, new Job('evt_3', 'paypal', 3), 'gave up');

        self::assertSame(['markFailed', 'deadLetter'], $this->log->methods());
        self::assertSame([9, 'gave up'], $this->log->argsFor('markFailed'));
        self::assertSame(['evt_3', 'paypal', 3], $this->log->argsFor('deadLetter'));
        self::assertNotContains('scheduleRetry', $this->log->methods());
        self::assertNotContains('markForRetry', $this->log->methods());
    }

    public function testDeadLetterWritesDbBeforeQueue(): void
    {
        $this->scheduler->deadLetter($this->events, 11, new Job('evt_4', 'github', 3), 'permanent');

        self::assertSame(['markFailed', 'deadLetter'], $this->log->methods());
        self::assertSame([11, 'permanent'], $this->log->argsFor('markFailed'));
        self::assertSame(['evt_4', 'github', 3], $this->log->argsFor('deadLetter'));
    }
}
