<?php

declare(strict_types=1);

namespace App\Verification;

use App\Config\ProviderSecrets;

/**
 * Maps a provider tag ('github' | 'stripe' | 'paypal') to its ProviderVerifier. This
 * is the string→verifier mapping the T1.1 contract deliberately deferred; it lives in
 * one place here so the controller never branches per provider.
 *
 * Construction is LAZY, per provider, on purpose: each verifier's fromSecrets() throws
 * VerificationException when ITS own secret/config is missing. If we built all three
 * eagerly, an unconfigured PayPal would throw and take down GitHub and Stripe ingestion
 * too. Building only the verifier for the provider actually being called keeps the
 * providers operationally isolated — a missing PayPal secret only ever fails a
 * /webhooks/paypal request.
 */
final class VerifierRegistry
{
    public function __construct(
        private readonly ProviderSecrets $secrets,
    ) {
    }

    /**
     * The verifier for $provider, constructed on demand, or null if the tag is unknown.
     *
     * "Unknown provider" is a routing concern (T1.6) — the registry only reports "I have
     * no verifier for this tag" and leaves the HTTP meaning to the caller. May throw
     * VerificationException if this provider's secret/config is absent (the controller
     * maps that to a 500).
     */
    public function get(string $provider): ?ProviderVerifier
    {
        return match ($provider) {
            'github' => GitHubVerifier::fromSecrets($this->secrets),
            'stripe' => StripeVerifier::fromSecrets($this->secrets),
            'paypal' => PayPalVerifier::fromSecrets($this->secrets),
            default => null,
        };
    }
}
