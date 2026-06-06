<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * The tally of a single DLQ drain (T3.2): how many dead-lettered events were put back on the
 * live path and how many were skipped because their audit row was missing or no longer 'failed'.
 *
 * A tiny immutable value object, the same "build it up, hand a typed result to the next layer"
 * shape as Job / WebhookEvent / VerificationResult: DlqRequeue accumulates the counts while it
 * drains, then returns ONE of these for bin/requeue-dlq.php to print as its summary. Keeping the
 * numbers in a typed object (rather than a loose array) means the CLI cannot misread a key and
 * the contract is checked by the type system.
 *
 * `skipped` counts entries whose envelope was popped off webhooks:dlq but whose row could NOT be
 * re-armed (requeueFromDlq returned false: no row, or a row not in 'failed') — those are NOT
 * re-enqueued, because there is nothing claimable to re-drive. `corrupt` counts envelopes that
 * could not even be decoded (a poison DLQ entry); they are dropped so one bad entry cannot block
 * the whole drain. `requeued + skipped + corrupt` equals the number of entries the drain consumed.
 */
final readonly class DlqRequeueReport
{
    public function __construct(
        private int $requeued,
        private int $skipped,
        private int $corrupt,
    ) {
    }

    /** Events reset to 'received' and re-pushed onto the main queue for a fresh attempt. */
    public function requeued(): int
    {
        return $this->requeued;
    }

    /** Envelopes popped whose row was absent or not 'failed' — not re-enqueued. */
    public function skipped(): int
    {
        return $this->skipped;
    }

    /** Envelopes that could not be decoded (poison DLQ entries) — dropped, drain continued. */
    public function corrupt(): int
    {
        return $this->corrupt;
    }

    /** Total entries consumed off the dead-letter list during the drain. */
    public function total(): int
    {
        return $this->requeued + $this->skipped + $this->corrupt;
    }
}
