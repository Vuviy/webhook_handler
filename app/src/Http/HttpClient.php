<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A deliberately tiny HTTP seam — only what the PayPal verifier needs: a POST with
 * headers and a body, returning the status and response body.
 *
 * Why an interface for a single curl call? So the remote PayPal calls are MOCKABLE:
 * tests inject a fake that returns canned responses (SUCCESS / FAILURE / 5xx /
 * transport throw) without ever touching the network. The production implementation
 * is CurlHttpClient. We keep this framework-free and minimal rather than pulling a
 * general HTTP toolkit (Guzzle) for two endpoints (see ADR 0008).
 */
interface HttpClient
{
    /**
     * Send a POST and return the completed exchange.
     *
     * @param array<string, string> $headers Header name => value (e.g.
     *                                        'Authorization' => 'Bearer ...').
     *
     * A non-2xx response is returned as a normal HttpResponse — inspect its status().
     *
     * @throws HttpException ONLY on a transport-level failure where no response was
     *                       produced (connect refused, DNS, timeout, curl error).
     */
    public function post(string $url, array $headers, string $body): HttpResponse;
}
