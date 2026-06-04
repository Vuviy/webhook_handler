<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown on Redis queue connection or job-encoding failures.
 *
 * Messages name at most the Redis host:port or the event id — never the job
 * payload or any secret — so nothing sensitive leaks into logs or stack traces.
 * Mirrors App\Exception\DatabaseException (T0.3).
 */
final class QueueException extends RuntimeException
{
    public static function connectionFailed(string $host, int $port, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to connect to Redis at "%s:%d".', $host, $port),
            0,
            $previous,
        );
    }

    public static function encodeFailed(string $eventId, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to encode the queue job for event "%s".', $eventId),
            0,
            $previous,
        );
    }

    public static function enqueueFailed(string $eventId, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to enqueue the job for event "%s".', $eventId),
            0,
            $previous,
        );
    }
}
