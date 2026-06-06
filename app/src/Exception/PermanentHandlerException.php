<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown by a Handler to signal an UN-RETRYABLE processing failure: a fault that
 * retrying cannot fix (a poison/malformed event, a business rejection). The worker's
 * reaction — straight to the DLQ, no retry, status=failed — is defined in T2.4; T2.1
 * only fixes this vocabulary (ADR 0011, Decision 2). Contrast TransientHandlerException,
 * which is retried.
 *
 * Messages must stay non-sensitive: name the cause ("unsupported event type"), never the
 * raw payload or a secret — they may reach logs / the dashboard's last_error. Mirrors
 * the typed-factory style of App\Exception\QueueException; carries the original $cause
 * as `previous` so the underlying error is not lost.
 */
final class PermanentHandlerException extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }
}
