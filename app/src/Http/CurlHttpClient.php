<?php

declare(strict_types=1);

namespace App\Http;

/**
 * curl-backed HttpClient. Hand-rolled over ext-curl (already in the image), in
 * keeping with the framework-free ethos — no Guzzle for two PayPal endpoints.
 *
 * The timeouts are MANDATORY and injectable: a hung PayPal must never wedge a
 * PHP-FPM worker, which would back up the whole ingestion path. A request that times
 * out surfaces as an HttpException (curl_exec returns false), which the verifier maps
 * to the Error outcome (→ 502), telling the provider to retry — never a false 401.
 *
 * TLS verification is left at curl's secure default: we never disable VERIFYPEER /
 * VERIFYHOST. We also never fetch the PayPal cert URL ourselves (PayPal does), so
 * this client only ever talks to the configured PayPal API base — no SSRF surface.
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly int $connectTimeoutSeconds = 2,
        private readonly int $totalTimeoutSeconds = 5,
    ) {
    }

    public function post(string $url, array $headers, string $body): HttpResponse
    {
        $ch = curl_init($url);

        // curl_init only returns false for a malformed URL; treat that as transport.
        if ($ch === false) {
            throw HttpException::transportFailure($url, 'could not initialise curl handle');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->totalTimeoutSeconds,
        ]);

        try {
            $responseBody = curl_exec($ch);

            // false = the request never produced a response (timeout, connect/DNS,
            // protocol error). Convert to a typed transport failure; curl_error never
            // contains our request body or headers, so no secret leaks.
            if ($responseBody === false) {
                throw HttpException::transportFailure($url, curl_error($ch));
            }

            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            return new HttpResponse($status, (string) $responseBody);
        } finally {
            curl_close($ch);
        }
    }
}
