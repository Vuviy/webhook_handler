<?php

declare(strict_types=1);

namespace App\Verification;

use App\Exception\VerificationException;

/**
 * The contract every provider's signature verifier implements (GitHub, Stripe,
 * PayPal — T1.2/T1.3/T1.4). This is the seam the ingestion controller (T1.5)
 * depends on, so the controller can verify a webhook without knowing which
 * provider's scheme is in play.
 *
 * Each concrete verifier receives its own provider secret (from
 * App\Config\ProviderSecrets) in its constructor — the interface itself stays
 * secret-free.
 */
interface ProviderVerifier
{
    /**
     * Verify the signature over the EXACT raw request bytes and, on success,
     * extract the provider event id and event type.
     *
     * @param string               $rawBody The untouched bytes from php://input
     *                                       (NFR-1). Never a re-encoded JSON string:
     *                                       a single re-serialisation would change
     *                                       the bytes and break the HMAC.
     * @param array<string, string> $headers A header map keyed by lower-cased header
     *                                       name (the controller normalises casing
     *                                       once). Each verifier reads only the
     *                                       header(s) its own scheme uses
     *                                       (e.g. "x-hub-signature-256",
     *                                       "stripe-signature", "paypal-*").
     *
     * A bad, missing or malformed signature is NOT an exception — it is the routine
     * VerificationResult::invalid(...) path (→ HTTP 401). The same holds for an empty
     * body or a missing signature header: return invalid(...), never throw, so a
     * hostile or malformed request can never become a 5xx (spec Risk §8). A remote
     * verification that could not complete (PayPal API down) is
     * VerificationResult::error(...) (→ 502).
     *
     * @throws VerificationException Only on misconfiguration — e.g. the provider
     *                               secret is absent. That is a deployment fault and
     *                               must surface as a 500, never be disguised as a
     *                               401, so it is signalled out-of-band rather than
     *                               folded into an Invalid result.
     */
    public function verify(string $rawBody, array $headers): VerificationResult;

    /**
     * The stable provider tag for this verifier, e.g. "github". A registry/factory
     * (built in T1.5) maps the /webhooks/{provider} route segment to the matching
     * verifier through this value.
     */
    public function provider(): string;
}
