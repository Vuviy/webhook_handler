<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * The result of verifying one webhook: an outcome plus, on success, the provider
 * event id and event type extracted from the body.
 *
 * Why carry the ids here instead of re-parsing the body in the controller? Because
 * pulling them out is provider-specific work the verifier already does while it has
 * the raw body in hand, and the controller needs both at ingestion time: event_id
 * is the dedupe key (UNIQUE (provider, event_id), FR-11) and event_type is a
 * NOT NULL column on webhook_events. Parsing the body once, in the party that
 * understands it, keeps the controller provider-agnostic.
 *
 * The constructor is private: callers go through the named factories so an
 * impossible state — e.g. a Valid result with no event id — cannot be built.
 *
 * Invariants:
 *  - Valid   → eventId and eventType are non-null; reason is null.
 *  - Invalid → eventId and eventType are null; reason is a short, safe message.
 *  - Error   → eventId and eventType are null; reason is a short, safe message.
 *
 * NFR-1: `reason` is for logs / the dashboard's last_error. It MUST NEVER contain
 * the signature, a secret, or raw payload bytes — only a human-readable cause such
 * as "missing Stripe-Signature header" or "timestamp outside tolerance".
 */
final readonly class VerificationResult
{
    private function __construct(
        private VerificationOutcome $outcome,
        private ?string $eventId,
        private ?string $eventType,
        private ?string $reason,
    ) {
    }

    public static function valid(string $eventId, string $eventType): self
    {
        // Enforce the "Valid ⇒ non-empty ids" invariant here, where it is cheap,
        // rather than letting an empty string slip through to the DB as a bad
        // dedupe key / a NOT NULL violation. A verifier handing back empty ids is a
        // programming error in that verifier, so this is an argument precondition.
        if ($eventId === '' || $eventType === '') {
            throw new \InvalidArgumentException(
                'A valid VerificationResult requires a non-empty event id and event type.',
            );
        }

        return new self(VerificationOutcome::Valid, $eventId, $eventType, null);
    }

    public static function invalid(string $reason): self
    {
        return new self(VerificationOutcome::Invalid, null, null, $reason);
    }

    public static function error(string $reason): self
    {
        return new self(VerificationOutcome::Error, null, null, $reason);
    }

    public function outcome(): VerificationOutcome
    {
        return $this->outcome;
    }

    public function isValid(): bool
    {
        return $this->outcome === VerificationOutcome::Valid;
    }

    /** Non-null only when the outcome is Valid. */
    public function eventId(): ?string
    {
        return $this->eventId;
    }

    /** Non-null only when the outcome is Valid. */
    public function eventType(): ?string
    {
        return $this->eventType;
    }

    /** A short, non-sensitive cause for Invalid/Error; null when Valid. */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
