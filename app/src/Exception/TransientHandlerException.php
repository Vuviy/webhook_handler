<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown by a Handler to signal a RETRYABLE processing failure: a fault that is
 * expected to clear on its own (downstream timeout, a 5xx from an upstream API, a lock
 * contention). The worker's reaction — retry with exponential backoff while attempts
 * remain, then DLQ — is defined in T2.3/T2.4; T2.1 only fixes this vocabulary
 * (ADR 0011, Decision 2). Contrast PermanentHandlerException, which skips retries.
 *
 * Messages must stay non-sensitive: name the cause ("payment API timed out"), never the
 * raw payload or a secret — they may reach logs / the dashboard's last_error. Mirrors
 * the typed-factory style of App\Exception\QueueException; carries the original $cause
 * as `previous` so the underlying error is not lost.
 */
final class TransientHandlerException extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }
}
