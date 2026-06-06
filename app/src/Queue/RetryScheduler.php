<?php

declare(strict_types=1);

namespace App\Queue;

use App\Repository\EventRepository;

/**
 * The retry POLICY for transiently-failed jobs (T2.3): how long to wait before the next
 * attempt, how many attempts are allowed, and the retry-or-give-up decision.
 *
 * It is the policy half of a deliberate split (ADR 0013, Decision 4): Queue owns the
 * MECHANISM (the webhooks:retry zset write and the zset→queue promotion, where the Redis
 * key names and the job envelope are sealed); RetryScheduler owns the POLICY (the
 * 2^(n-1)·base backoff math, jitter, and the MAX_ATTEMPTS boundary). Keeping the numbers
 * here — out of Queue — means the backoff is tuned and unit-tested in one place without
 * touching Redis.
 *
 * Backoff (ADR 0013, Decision 6, resolving spec open question #2 in favour of SECONDS):
 * exponential with EQUAL jitter. For attempt n the nominal delay is D = 2^(n-1)·2s and the
 * actual delay is D/2 + rand(0, D/2) — a guaranteed minimum spacing (never 0) that still
 * spreads a thundering herd, safer than full jitter which can collapse to ~0. Concretely
 * the retries scheduled for attempts 2 and 3 wait 2–4s and 4–8s.
 */
final class RetryScheduler
{
    /** Base unit of the exponential backoff, in seconds (ADR 0013, Decision 6). */
    private const BASE_DELAY_SECONDS = 2;

    /**
     * Total number of delivery attempts allowed before a job is exhausted (FR-7,
     * "max 3 attempts"). attempt 1 is the initial delivery; attempts 2 and 3 are the
     * retries; a would-be attempt 4 is exhausted and handed to the interim markFailed
     * (the T2.4 DLQ seam).
     */
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly Queue $queue,
    ) {
    }

    /**
     * Sweep any now-due retries from the webhooks:retry zset back onto the main queue.
     * Called once per worker iteration before the blocking pop (ADR 0013, Decision 1), so a
     * due retry is re-popped within one loop turn. Delegates the zset mechanics to Queue.
     */
    public function promoteDue(): void
    {
        $this->queue->promoteDueRetries(time());
    }

    /**
     * The transient-failure boundary, kept in ONE place so the worker's two transient catch
     * blocks (TransientHandlerException and the catch-all Throwable) stay identical
     * (ADR 0013, Decision 5).
     *
     * While attempts remain (next <= MAX_ATTEMPTS): bump the row back to 'received' with the
     * new attempt count and last_error (markForRetry re-arms the guarded claim), then ZADD the
     * envelope for a delayed retry. The DB write comes FIRST so that if the zset write fails the
     * row is left at the recoverable 'received' rather than stranded mid-transition.
     *
     * On EXHAUSTION (next > MAX_ATTEMPTS): interim markFailed — an honest, audited terminal
     * 'failed' with the error. This is the documented T2.4 seam: T2.4 reroutes this branch to
     * webhooks:dlq by addition, not rewrite (ADR 0013, Decision 5).
     */
    public function retryOrFail(EventRepository $events, int $id, Job $job, string $error): void
    {
        $next = $job->attempt() + 1;

        if ($next > self::MAX_ATTEMPTS) {
            $events->markFailed($id, $error);

            return;
        }

        $events->markForRetry($id, $next, $error);
        $this->queue->scheduleRetry($job->eventId(), $job->provider(), $next, time() + $this->delayFor($next));
    }

    /**
     * Exponential backoff with equal jitter for the given 1-based attempt number:
     * D = 2^(attempt-1)·BASE, delay = D/2 + rand(0, D/2). random_int (a CSPRNG) is used for
     * the jitter — there is no security need, but it is the modern default and sidesteps the
     * seeding concerns of mt_rand. Returns whole seconds (the zset score is a unix time).
     */
    public function delayFor(int $attempt): int
    {
        $nominal = (2 ** ($attempt - 1)) * self::BASE_DELAY_SECONDS;
        $half = intdiv($nominal, 2);

        return $half + random_int(0, $half);
    }
}
