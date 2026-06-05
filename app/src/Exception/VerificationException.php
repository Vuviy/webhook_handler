<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Thrown when a webhook cannot be verified because of a CONFIGURATION fault —
 * not because the signature is bad. A forged or missing signature is the routine
 * App\Verification\VerificationResult::invalid(...) path (→ 401); this exception is
 * reserved for "we are not set up to verify this provider at all" (e.g. its secret
 * is absent), which is a deployment fault and must surface as a 500.
 *
 * Messages name only the provider — never the secret, signature or payload — so
 * nothing sensitive leaks into logs or stack traces. Mirrors the typed-factory
 * style of App\Exception\QueueException and App\Exception\DatabaseException.
 */
final class VerificationException extends RuntimeException
{
    public static function missingSecret(string $provider): self
    {
        return new self(
            sprintf('Webhook secret for provider "%s" is not configured.', $provider),
        );
    }

    /**
     * A named required configuration key is absent. Names the env key (e.g.
     * PAYPAL_CLIENT_ID) but NEVER its value, for clearer 500-time diagnostics when a
     * provider needs more than a single secret (PayPal: webhook id + credentials).
     */
    public static function missingConfig(string $provider, string $key): self
    {
        return new self(
            sprintf('Required configuration "%s" for provider "%s" is not set.', $key, $provider),
        );
    }
}
