<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A completed HTTP exchange: a status code plus the response body.
 *
 * A response of ANY status — including 4xx/5xx — is a successful exchange and a
 * valid HttpResponse. Non-2xx is NOT an exception: the caller inspects status() and
 * decides what it means. Only a transport-level failure (the request never produced
 * a response at all — DNS, connect refused, timeout) is signalled out-of-band, as an
 * HttpException thrown by the client.
 */
final readonly class HttpResponse
{
    public function __construct(
        private int $status,
        private string $body,
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }
}
