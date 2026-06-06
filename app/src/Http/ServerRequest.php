<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A typed, testable view over the raw HTTP request: method, path, headers, raw body.
 *
 * Why a class instead of reading $_SERVER inline in the front controller? Two reasons:
 *  - getallheaders() is NOT available in this SAPI setup, so headers must be
 *    reconstructed from $_SERVER's HTTP_* keys. That transform is fiddly (prefix
 *    strip, case, underscore→dash) and worth unit-testing — which means it must live
 *    outside index.php (an entry script can't be unit-tested). fromGlobals() takes the
 *    $server array as an argument, so a test passes a fake map with no real request.
 *  - It keeps index.php a thin wiring script.
 *
 * Like the IngestionController, this object never touches a superglobal itself and
 * never reads php://input — the caller passes both in, so behaviour is fully
 * determined by its inputs.
 */
final readonly class ServerRequest
{
    /**
     * @param array<string, string> $headers Lower-cased, dash-named header map
     *                                        (e.g. 'x-hub-signature-256').
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $headers,
        private string $rawBody,
    ) {
    }

    /**
     * @param array<string, mixed> $server  Typically $_SERVER.
     * @param string               $rawBody Typically file_get_contents('php://input').
     */
    public static function fromGlobals(array $server, string $rawBody): self
    {
        // Default to GET so a missing method can never cause a type error downstream.
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        // parse_url strips the query string; fall back to '/' on any parse failure.
        $path = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        return new self($method, $path, self::extractHeaders($server), $rawBody);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Reconstruct request headers from $_SERVER's HTTP_* entries:
     * HTTP_X_HUB_SIGNATURE_256 → x-hub-signature-256.
     *
     * CONTENT_TYPE / CONTENT_LENGTH arrive WITHOUT the HTTP_ prefix and are
     * deliberately NOT included: every signature header the verifiers read
     * (X-Hub-Signature-256, Stripe-Signature, the Paypal-* set) is an HTTP_* header,
     * so a verifier never needs content-type/length. This is intentional, not an
     * oversight.
     *
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string) $value;
        }

        return $headers;
    }
}
