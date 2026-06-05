<?php

declare(strict_types=1);

namespace App\Verification;

use App\Config\ProviderSecrets;
use App\Exception\VerificationException;
use Closure;
use JsonException;

/**
 * Verifies Stripe webhooks: HMAC-SHA256 over a timestamped payload, with replay
 * protection. The house pattern is shared with GitHubVerifier; Stripe adds three
 * twists that shape this class.
 *
 *  1. Composite signature header. `Stripe-Signature: t=<unix_ts>,v1=<hex>,v1=<hex>`
 *     is a comma-separated list, not a single value: exactly one `t` (the timestamp)
 *     and one OR MORE `v1` digests (more than one during a signing-secret rotation).
 *     We accept the request if our computed digest matches ANY `v1` (timing-safe),
 *     and we ignore other schemes such as the legacy `v0`.
 *
 *  2. The signed string is `"{t}.{rawBody}"` — the literal timestamp, a dot, then the
 *     untouched raw body bytes. So `t` is itself covered by the signature: a forged
 *     timestamp changes the HMAC and fails verification.
 *
 *  3. Replay protection. Even a correctly-signed request is rejected if its `t` is
 *     too far from now (default tolerance 300s). A captured-and-resent old request
 *     carries a genuine, correctly-signed `t` from the past, so the signature alone
 *     would accept it — the timestamp window is what closes the replay hole. We check
 *     the window BEFORE computing the HMAC: it consumes no secret and leaks nothing
 *     (the attacker sent `t`), and it sheds stale replays before doing crypto work.
 *     We never trust `t` for anything beyond this freshness check until the HMAC over
 *     the whole `"{t}.{rawBody}"` has passed.
 *
 * Unlike GitHub (id/type in headers), Stripe carries the dedupe id and event type in
 * the JSON BODY (`id` = "evt_...", `type` = "payment_intent.succeeded"). So this
 * verifier decodes the body — but ONLY after the signature passes (golden rule: never
 * trust the payload before it is authenticated). A valid signature over a body we then
 * cannot read (not JSON, or missing id/type) is still `invalid()`, not `error()`: we
 * cannot dedupe without an id, so we fail closed. `error()` stays reserved for "could
 * not verify at all" (a remote outage), which never happens for local HMAC.
 *
 * The clock is injected (a Closure(): int, defaulting to time(...)) so the replay
 * window is testable without sleeping or mocking global time. Compares use
 * hash_equals(), never ==, for the usual timing-attack reason (see GitHubVerifier).
 */
final class StripeVerifier implements ProviderVerifier
{
    private const PROVIDER = 'stripe';
    private const SIGNATURE_HEADER = 'stripe-signature';
    private const TIMESTAMP_KEY = 't';
    private const SIGNATURE_SCHEME = 'v1';
    private const TOLERANCE_SECONDS = 300;

    /**
     * @param Closure(): int $clock Returns the current unix time. Injected so the
     *                              replay-window branch is testable; production uses
     *                              time(...) (supplied by fromSecrets()).
     */
    public function __construct(
        private readonly string $secret,
        private readonly Closure $clock,
        private readonly int $toleranceSeconds = self::TOLERANCE_SECONDS,
    ) {
    }

    /**
     * Build the verifier from configured secrets, failing fast if Stripe's signing
     * secret (the `whsec_...` value) is absent. A missing secret is a deployment
     * fault (→ 500), never a bad signature (→ 401) — see GitHubVerifier::fromSecrets.
     */
    public static function fromSecrets(ProviderSecrets $secrets): self
    {
        $secret = $secrets->stripeSecret();

        if ($secret === null) {
            throw VerificationException::missingSecret(self::PROVIDER);
        }

        return new self($secret, time(...));
    }

    public function verify(string $rawBody, array $headers): VerificationResult
    {
        if ($rawBody === '') {
            return VerificationResult::invalid('empty request body');
        }

        $header = $headers[self::SIGNATURE_HEADER] ?? '';

        if ($header === '') {
            return VerificationResult::invalid('missing Stripe-Signature header');
        }

        // Parse the composite header into one timestamp + a list of v1 candidates.
        // A single malformed pair is skipped rather than voiding an otherwise valid
        // header; a missing t or v1 is fatal (invalid), never an exception.
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = [trim($pair[0]), trim($pair[1])];

            if ($key === self::TIMESTAMP_KEY) {
                // Stripe sends exactly one t; if a request somehow carries several,
                // last-wins is safe — t is part of the signed string "{t}.{rawBody}",
                // so only the t we actually pick is HMAC-checked, and a mismatched
                // injected t simply fails the signature. No bypass, so no need to reject.
                $timestamp = $value;
            } elseif ($key === self::SIGNATURE_SCHEME) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null) {
            return VerificationResult::invalid('missing t in Stripe-Signature');
        }

        if ($signatures === []) {
            return VerificationResult::invalid('missing v1 signature in Stripe-Signature');
        }

        // ctype_digit rejects '', signs, decimals and whitespace cleanly. Note it is
        // false for '' so an empty t never slips through.
        if (!ctype_digit($timestamp)) {
            return VerificationResult::invalid('non-numeric t in Stripe-Signature');
        }

        // Replay / clock-skew window. Checked before the HMAC on purpose (see class doc).
        if (abs(($this->clock)() - (int) $timestamp) > $this->toleranceSeconds) {
            return VerificationResult::invalid('timestamp outside tolerance');
        }

        // Sign the LITERAL timestamp bytes as received (not the re-cast int) joined to
        // the raw body. Compute the HMAC once; the loop is then just constant-time
        // compares (no per-candidate crypto), so a stuffed header cannot force repeat
        // HMAC work.
        $computed = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);

        $matched = false;
        foreach ($signatures as $candidate) {
            if (hash_equals($computed, $candidate)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return VerificationResult::invalid('signature mismatch');
        }

        // Signature is authentic — only now decode the body for id/type. A JsonException
        // must never escape as a 5xx: a malformed body is the routine invalid path.
        try {
            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return VerificationResult::invalid('payload is not valid JSON');
        }

        if (!is_array($data)) {
            return VerificationResult::invalid('payload is not a JSON object');
        }

        // is_string guards against id/type arriving as a number/array/null, which would
        // make trim() a TypeError (a 5xx) instead of a clean invalid.
        $eventId = is_string($data['id'] ?? null) ? trim($data['id']) : '';
        $eventType = is_string($data['type'] ?? null) ? trim($data['type']) : '';

        if ($eventId === '' || $eventType === '') {
            return VerificationResult::invalid('missing id or type in Stripe payload');
        }

        return VerificationResult::valid($eventId, $eventType);
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }
}
