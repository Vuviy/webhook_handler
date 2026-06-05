<?php

declare(strict_types=1);

namespace App\Verification;

use App\Config\ProviderSecrets;
use App\Exception\VerificationException;
use App\Http\CurlHttpClient;
use App\Http\HttpClient;
use App\Http\HttpException;
use JsonException;

/**
 * Verifies PayPal webhooks by ASKING PayPal — unlike GitHub/Stripe, there is no
 * shared secret to recompute an HMAC with locally. We POST the five `paypal-*`
 * transmission headers plus our configured webhook id plus the parsed event to
 * `/v1/notifications/verify-webhook-signature` and read back `verification_status`.
 * That call needs an OAuth2 bearer token from `/v1/oauth2/token` (HTTP Basic with the
 * client id/secret), so each verification is TWO remote round-trips.
 *
 * Because verification is remote, this is the only verifier that can fail because we
 * could not REACH the verifier, rather than because the signature is bad. That is the
 * Error outcome the contract was shaped for:
 *
 *  - token fetch or verify call fails at transport level / times out / returns non-2xx
 *    / returns an unreadable body  → error(...)  → the controller answers 502 and the
 *    provider retries. This is OUR outage, never the caller's fault — never a 401.
 *  - PayPal explicitly answers verification_status != SUCCESS  → invalid(...)  → 401.
 *    That is a genuine bad/forged signature.
 *
 * There is no hash_equals here: no local crypto happens, the comparison is PayPal's.
 *
 * We must json_decode the body BEFORE verifying, because the verify request embeds the
 * event as `webhook_event`. That does not break the golden rule ("don't trust the
 * payload before verifying"): we only FORWARD the parsed body to PayPal; we never act
 * on its contents or extract the id/type until PayPal answers SUCCESS.
 *
 * No SSRF surface: we never fetch `paypal-cert-url` ourselves (PayPal validates the
 * cert); we only forward the header value, and our HttpClient only ever calls the
 * configured PayPal API base.
 *
 * Config is resolved once at construction (fromSecrets): a constructed verifier is
 * guaranteed to have webhook id + credentials, so a missing one surfaces as a 500
 * before any request is served, never as a misleading 401.
 */
final class PayPalVerifier implements ProviderVerifier
{
    private const PROVIDER = 'paypal';

    private const H_TRANSMISSION_ID = 'paypal-transmission-id';
    private const H_TRANSMISSION_TIME = 'paypal-transmission-time';
    private const H_TRANSMISSION_SIG = 'paypal-transmission-sig';
    private const H_CERT_URL = 'paypal-cert-url';
    private const H_AUTH_ALGO = 'paypal-auth-algo';

    private const TOKEN_PATH = '/v1/oauth2/token';
    private const VERIFY_PATH = '/v1/notifications/verify-webhook-signature';

    private const STATUS_SUCCESS = 'SUCCESS';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $apiBase,
        private readonly string $webhookId,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    /**
     * Build the verifier from configured secrets, failing fast (→ 500) if any required
     * PayPal config is absent. The API base is always present (it carries a sandbox
     * default in ProviderSecrets), so only the three credentials are guarded.
     */
    public static function fromSecrets(ProviderSecrets $secrets): self
    {
        $webhookId = $secrets->paypalWebhookId();
        $clientId = $secrets->paypalClientId();
        $clientSecret = $secrets->paypalClientSecret();

        if ($webhookId === null) {
            throw VerificationException::missingConfig(self::PROVIDER, 'PAYPAL_WEBHOOK_ID');
        }

        if ($clientId === null) {
            throw VerificationException::missingConfig(self::PROVIDER, 'PAYPAL_CLIENT_ID');
        }

        if ($clientSecret === null) {
            throw VerificationException::missingConfig(self::PROVIDER, 'PAYPAL_CLIENT_SECRET');
        }

        return new self(
            new CurlHttpClient(),
            $secrets->paypalApiBase(),
            $webhookId,
            $clientId,
            $clientSecret,
        );
    }

    public function verify(string $rawBody, array $headers): VerificationResult
    {
        if ($rawBody === '') {
            return VerificationResult::invalid('empty request body');
        }

        // All five transmission headers are required to even build the verify request.
        // A missing one is a malformed/forged-shaped request → fail closed (401).
        $transmission = [
            'transmission_id' => trim($headers[self::H_TRANSMISSION_ID] ?? ''),
            'transmission_time' => trim($headers[self::H_TRANSMISSION_TIME] ?? ''),
            'cert_url' => trim($headers[self::H_CERT_URL] ?? ''),
            'auth_algo' => trim($headers[self::H_AUTH_ALGO] ?? ''),
            'transmission_sig' => trim($headers[self::H_TRANSMISSION_SIG] ?? ''),
        ];

        foreach ($transmission as $value) {
            if ($value === '') {
                return VerificationResult::invalid('missing PayPal transmission headers');
            }
        }

        // Parse the body to forward as `webhook_event` — NOT to trust yet.
        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return VerificationResult::invalid('payload is not valid JSON');
        }

        if (!is_array($event)) {
            return VerificationResult::invalid('payload is not a JSON object');
        }

        $token = $this->fetchAccessToken();
        if ($token === null) {
            return VerificationResult::error('could not obtain PayPal access token');
        }

        $status = $this->requestVerification($transmission, $event, $token);
        if ($status === null) {
            return VerificationResult::error('PayPal verification request failed');
        }

        if ($status !== self::STATUS_SUCCESS) {
            return VerificationResult::invalid('PayPal verification_status not SUCCESS');
        }

        // Authentic — only now extract id/type from the body. is_string guards against
        // a number/array/null making trim() a TypeError (5xx) instead of a clean invalid.
        $eventId = is_string($event['id'] ?? null) ? trim($event['id']) : '';
        $eventType = is_string($event['event_type'] ?? null) ? trim($event['event_type']) : '';

        if ($eventId === '' || $eventType === '') {
            return VerificationResult::invalid('missing id or event_type in PayPal payload');
        }

        return VerificationResult::valid($eventId, $eventType);
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    /**
     * Fetch an OAuth2 bearer token (client_credentials grant). Returns null on any
     * failure — transport, non-2xx, or a body without a usable access_token — so the
     * caller turns a single null into the error() outcome. We never throw here: a
     * PayPal outage must not become a 5xx escaping the verifier.
     */
    private function fetchAccessToken(): ?string
    {
        try {
            $response = $this->http->post(
                $this->apiBase . self::TOKEN_PATH,
                [
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'grant_type=client_credentials',
            );
        } catch (HttpException) {
            return null;
        }

        // Anything outside 2xx — including a 3xx — counts as failure: PayPal never
        // legitimately redirects these endpoints, and following a redirect blindly
        // would be an SSRF foothold. A failed call becomes error() (502), not 401.
        if ($response->status() < 200 || $response->status() >= 300) {
            return null;
        }

        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            return null;
        }

        return $data['access_token'];
    }

    /**
     * POST the verify-webhook-signature request and return the verification_status
     * string, or null on any failure (transport, non-2xx, unreadable/incomplete body)
     * so the caller maps null to error().
     *
     * @param array<string, string> $transmission The five transmission fields.
     * @param array<mixed>          $event        The parsed webhook event to forward.
     */
    private function requestVerification(array $transmission, array $event, string $token): ?string
    {
        try {
            $payload = json_encode([
                'transmission_id' => $transmission['transmission_id'],
                'transmission_time' => $transmission['transmission_time'],
                'cert_url' => $transmission['cert_url'],
                'auth_algo' => $transmission['auth_algo'],
                'transmission_sig' => $transmission['transmission_sig'],
                'webhook_id' => $this->webhookId,
                'webhook_event' => $event,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        try {
            $response = $this->http->post(
                $this->apiBase . self::VERIFY_PATH,
                [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                $payload,
            );
        } catch (HttpException) {
            return null;
        }

        // Anything outside 2xx — including a 3xx — counts as failure: PayPal never
        // legitimately redirects these endpoints, and following a redirect blindly
        // would be an SSRF foothold. A failed call becomes error() (502), not 401.
        if ($response->status() < 200 || $response->status() >= 300) {
            return null;
        }

        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || !is_string($data['verification_status'] ?? null)) {
            return null;
        }

        return $data['verification_status'];
    }
}
