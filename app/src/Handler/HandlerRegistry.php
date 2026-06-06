<?php

declare(strict_types=1);

namespace App\Handler;

/**
 * Maps a provider tag ('github' | 'stripe' | 'paypal') to its Handler, so the worker
 * (T2.2) never branches per provider. The string→handler counterpart of
 * App\Verification\VerifierRegistry (ADR 0011).
 *
 * Construction uses a lazy `match` for SYMMETRY with VerifierRegistry, not for the same
 * reason. VerifierRegistry builds lazily for operational isolation — each verifier's
 * fromSecrets() can throw if ITS secret is missing, so building all three eagerly would
 * let an unconfigured PayPal take down GitHub ingestion. That rationale does NOT apply
 * here: the stub handlers take no secrets and no config and cannot throw on construction.
 * `match` is kept only because it mirrors the established seam and costs nothing. If a
 * real handler later needs config, it can grow a fromConfig() factory at that point.
 */
final class HandlerRegistry
{
    /**
     * The handler for $provider, or null if the tag is unknown.
     *
     * "Unknown provider" is left to the caller to interpret (the worker will decide what
     * an unroutable job means — likely the DLQ, in T2.2), exactly as VerifierRegistry
     * leaves the HTTP meaning of an unknown tag to the controller.
     */
    public function get(string $provider): ?Handler
    {
        return match ($provider) {
            'github' => new GitHubHandler(),
            'stripe' => new StripeHandler(),
            'paypal' => new PayPalHandler(),
            default => null,
        };
    }
}
