<?php

declare(strict_types=1);

namespace App\Handler;

use App\Exception\PermanentHandlerException;
use App\Exception\TransientHandlerException;

/**
 * The contract every provider's processing step implements (github / stripe / paypal).
 * This is the seam the worker (T2.2) dispatches through: it obtains the right Handler
 * from HandlerRegistry and calls handle() — without knowing which provider's business
 * logic runs. Mirrors the App\Verification\ProviderVerifier seam (ADR 0011).
 *
 * The interface is justified despite the "avoid one-implementation abstractions" rule
 * (php-conventions): there are THREE implementations plus a registry, exactly like the
 * verifier seam.
 *
 * Delivery is at-least-once (NFR-2): the same event can be handled more than once
 * (e.g. the worker crashes after the work but before acking). Real implementations
 * MUST therefore be idempotent — dedupe by the provider event id (FR-11). The stub
 * implementations shipped in T2.1 are no-ops, hence idempotent by construction.
 */
interface Handler
{
    /**
     * Process one verified webhook. Returning normally means SUCCESS; the worker then
     * marks the event processed.
     *
     * Failure is signalled by throwing (ADR 0011, Decision 2):
     *  - throw TransientHandlerException for a retryable fault (downstream timeout,
     *    5xx, lock contention) — the worker retries with backoff while attempts remain;
     *  - throw PermanentHandlerException for an un-retryable fault (poison/malformed
     *    event, business rejection) — the worker sends it straight to the DLQ.
     *
     * The worker's REACTION to these (retry counts, backoff, DLQ, and the rule that any
     * OTHER Throwable is treated as transient so an unforeseen bug is retried rather than
     * silently dropped) is owned by T2.3/T2.4, not by this interface. T2.1 fixes only the
     * vocabulary.
     *
     * @throws TransientHandlerException Retryable failure — the worker should retry.
     * @throws PermanentHandlerException Un-retryable failure — straight to the DLQ.
     */
    public function handle(WebhookEvent $event): void;

    /**
     * The stable provider tag this handler serves, e.g. "github". HandlerRegistry maps
     * the job's provider field to the matching handler through this value.
     */
    public function provider(): string;
}
