<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * The slice of Queue's behaviour that the retry POLICY depends on (T3.3 testability seam).
 *
 * RetryScheduler needs exactly three queue operations — schedule a delayed retry, dead-letter an
 * exhausted job, and promote now-due retries — and nothing else. Extracting them here lets
 * RetryScheduler depend on an ABSTRACTION rather than the concrete `final class Queue`, so a test
 * can substitute an in-memory double (the second implementation, alongside the real Queue) and
 * assert the exact calls without a live Redis. This mirrors the existing App\Http\HttpClient seam:
 * one production implementation (CurlHttpClient there, Queue here) plus test fakes. The name follows
 * the house role-name style (HttpClient / ProviderVerifier), not a "*Interface" suffix.
 *
 * It is deliberately MINIMAL: it declares only what RetryScheduler uses, not Queue's full surface
 * (enqueue/consume/deadLetterSize/requeueOneFromDeadLetter stay off it). Queue remains the sole
 * owner of the Redis key names and the job-envelope schema; this interface only names the methods.
 */
interface RetryQueue
{
    /** Schedule a delayed retry: ZADD the job envelope to webhooks:retry with score = $readyAt. */
    public function scheduleRetry(string $eventId, string $provider, int $attempt, int $readyAt): void;

    /** Move an exhausted/permanently-failed job to terminal storage: RPUSH to webhooks:dlq. */
    public function deadLetter(string $eventId, string $provider, int $attempt): void;

    /** Sweep now-due retries from webhooks:retry back onto the main queue; returns how many moved. */
    public function promoteDueRetries(int $now): int;
}
