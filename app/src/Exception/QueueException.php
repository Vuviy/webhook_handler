<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown on Redis queue connection, job-encoding or job-decoding failures.
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

    /**
     * A job popped off the queue could not be decoded — invalid JSON or a malformed
     * envelope (missing/wrong-typed fields). The message is intentionally generic: the
     * raw bytes are NOT included, since a corrupt envelope might carry arbitrary content.
     */
    public static function decodeFailed(?Throwable $previous = null): self
    {
        return new self('Failed to decode a queue job: malformed envelope.', 0, $previous);
    }
}
