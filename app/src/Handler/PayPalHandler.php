<?php

declare(strict_types=1);

namespace App\Handler;

/**
 * Stub handler for PayPal webhooks (T2.1). Acting on the business meaning of an event
 * is out of scope beyond a pluggable stub (spec Non-goal §3), so handle() is a no-op:
 * it does NOT write to the DB, update status, log the payload, or touch Redis — those
 * belong to the worker (T2.2+).
 *
 * When a real implementation arrives it MUST be idempotent under at-least-once delivery
 * (FR-11): dedupe by the provider event id ($event->eventId()) so a re-processed event
 * has no double effect. It may throw TransientHandlerException / PermanentHandlerException
 * to drive retry vs DLQ (see Handler). A no-op is idempotent by construction.
 */
final class PayPalHandler implements Handler
{
    public function handle(WebhookEvent $event): void
    {
        // Intentionally empty: the PayPal business logic is out of scope for T2.1.
    }

    public function provider(): string
    {
        return 'paypal';
    }
}
