<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Webhook signing secrets per provider. Consumed by the verifiers (T1.x).
 *
 * Secrets are OPTIONAL at bootstrap: the app must be able to boot and answer the
 * health endpoint before any provider is configured. A missing secret only fails
 * later, inside that provider's verifier, with a clear error — not at startup.
 * compose does not inject these, so they come from app/.env (mounted into both
 * the php and worker containers).
 */
final readonly class ProviderSecrets
{
    public function __construct(
        private ?string $githubSecret,
        private ?string $stripeSecret,
        private ?string $paypalWebhookId,
        private ?string $paypalClientId,
        private ?string $paypalClientSecret,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            githubSecret: Env::get('GITHUB_WEBHOOK_SECRET'),
            stripeSecret: Env::get('STRIPE_WEBHOOK_SECRET'),
            paypalWebhookId: Env::get('PAYPAL_WEBHOOK_ID'),
            paypalClientId: Env::get('PAYPAL_CLIENT_ID'),
            paypalClientSecret: Env::get('PAYPAL_CLIENT_SECRET'),
        );
    }

    public function githubSecret(): ?string
    {
        return $this->githubSecret;
    }

    public function stripeSecret(): ?string
    {
        return $this->stripeSecret;
    }

    public function paypalWebhookId(): ?string
    {
        return $this->paypalWebhookId;
    }

    public function paypalClientId(): ?string
    {
        return $this->paypalClientId;
    }

    public function paypalClientSecret(): ?string
    {
        return $this->paypalClientSecret;
    }
}
