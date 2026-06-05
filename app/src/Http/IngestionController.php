<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\QueueException;
use App\Exception\VerificationException;
use App\Queue\Queue;
use App\Repository\EventRepository;
use App\Verification\VerificationOutcome;
use App\Verification\VerificationResult;
use App\Verification\VerifierRegistry;
use PDOException;

/**
 * The thin ingestion path: read nothing heavy, just verify → record → enqueue → 202.
 * This is the rule the whole system hangs on (CLAUDE.md): the HTTP request must never
 * do the real work, because providers retry on a slow or non-2xx response. So we
 * acknowledge fast and let the worker process asynchronously.
 *
 * The controller is PURE: it takes the provider, the raw body and the headers as
 * arguments and returns an IngestionResponse. It never touches php://input,
 * getallheaders() or any superglobal — that wiring is the front controller's job
 * (T1.6) — which keeps every branch here unit-testable without a live request.
 *
 * Outcome → HTTP (ADR 0009), all retry-aware:
 *   - Valid     → persist (status=received) + enqueue if new → 202.
 *   - Valid dup → already recorded, do NOT re-enqueue, still 202 (idempotent, AC-6).
 *   - Invalid   → 401, nothing persisted (a forged request has no trustworthy id).
 *   - Error     → 502, nothing persisted (our verifier outage — provider should retry).
 *   - misconfig → 500 (VerificationException: a secret/config is missing).
 *   - infra fail→ 500 (DB or queue error after verify — provider redelivers, dedupe heals).
 *   - unknown   → 404 (no verifier for this tag; mostly a T1.6 routing concern).
 *
 * NFR-1: no secret, signature, verification reason or payload byte is ever put into a
 * response body — only the status code carries meaning to the provider.
 */
final class IngestionController
{
    public function __construct(
        private readonly VerifierRegistry $verifiers,
        private readonly EventRepository $events,
        private readonly Queue $queue,
    ) {
    }

    /**
     * @param array<string, string> $headers Header map as received (any key casing);
     *                                        normalised to lower-case here before the
     *                                        verifier sees it.
     */
    public function handle(string $provider, string $rawBody, array $headers): IngestionResponse
    {
        try {
            $verifier = $this->verifiers->get($provider);
        } catch (VerificationException) {
            // A missing/!invalid secret is OUR deployment fault, never a bad signature:
            // surface 500, not 401, so we don't tell a legitimate provider it's wrong.
            return IngestionResponse::rejected(500);
        }

        if ($verifier === null) {
            return IngestionResponse::rejected(404);
        }

        // Verifiers require lower-cased header keys; the controller owns that contract.
        $result = $verifier->verify($rawBody, array_change_key_case($headers, CASE_LOWER));

        return match ($result->outcome()) {
            VerificationOutcome::Invalid => IngestionResponse::rejected(401),
            VerificationOutcome::Error => IngestionResponse::rejected(502),
            VerificationOutcome::Valid => $this->record($provider, $result, $rawBody),
        };
    }

    /**
     * Persist the verified event and enqueue it if newly recorded.
     *
     * A DB or queue failure here becomes a 500 rather than escaping: the provider then
     * redelivers and the dedupe-insert makes the retry harmless. We never let the raw
     * exception bubble — a stack trace could otherwise carry the payload into the 500.
     */
    private function record(string $provider, VerificationResult $result, string $rawBody): IngestionResponse
    {
        $eventId = $result->eventId();
        $eventType = $result->eventType();

        // A Valid result guarantees both ids are non-null and non-empty
        // (VerificationResult::valid()). The explicit guard makes that invariant visible
        // to the type checker and fails closed (500) rather than crashing if it were ever
        // broken — record() is only ever reached on the Valid arm of handle().
        if ($eventId === null || $eventType === null) {
            return IngestionResponse::rejected(500);
        }

        try {
            $inserted = $this->events->insertReceived($provider, $eventId, $eventType, $rawBody);

            if ($inserted) {
                $this->queue->enqueue($eventId, $provider);
            }
        } catch (PDOException | QueueException) {
            return IngestionResponse::rejected(500);
        }

        return IngestionResponse::accepted();
    }
}
