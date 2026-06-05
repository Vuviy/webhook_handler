<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * Thrown on a transport-level HTTP failure — the request never produced a response
 * (connect refused, DNS failure, timeout, curl error). A completed exchange with a
 * non-2xx status is NOT this; it is a normal HttpResponse.
 *
 * Messages name only the URL and the low-level transport error — NEVER the request
 * body, an Authorization header or any secret — so nothing sensitive leaks into logs
 * or stack traces. Mirrors the typed-factory style of App\Exception\QueueException.
 */
final class HttpException extends RuntimeException
{
    public static function transportFailure(string $url, string $error): self
    {
        return new self(
            sprintf('HTTP request to "%s" failed at transport level: %s', $url, $error),
        );
    }
}
