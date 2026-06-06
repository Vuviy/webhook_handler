<?php

declare(strict_types=1);

namespace App\Queue;

use App\Exception\QueueException;
use App\Repository\EventRepository;
use Closure;

/**
 * The recovery-direction policy for the dead-letter queue (T3.2): drain webhooks:dlq and put each
 * dead-lettered event back on the live path so the worker re-processes it once the downstream is
 * fixed (FR-8's recovery half).
 *
 * It is the deliberate mirror of RetryScheduler, kept as a SEPARATE class on purpose (ADR 0017,
 * Decision 4): RetryScheduler owns the FAILURE direction (received → retry → DLQ — backoff,
 * exhaustion, dead-letter); DlqRequeue owns the RECOVERY direction (DLQ → received). Folding the
 * drain onto RetryScheduler would stretch its name past breaking and mix two opposite flows in one
 * class. Like RetryScheduler it owns POLICY only and delegates MECHANISM: Queue still owns the
 * Redis keys + envelope (requeueOneFromDeadLetter / enqueue), EventRepository still owns the SQL
 * (requeueFromDlq). DlqRequeue just owns the cross-store ORDERING and the tally.
 *
 * The ordering (ADR 0017, Decision 3) — pop, then reset the DB row, then re-push — is the
 * load-bearing decision. It touches two stores with no cross-store transaction, so it is chosen so
 * the ONLY possible crash residue is a visible/recoverable state, never a silent loss:
 *   1. LPOP the envelope off webhooks:dlq FIRST  — consuming it up front means a re-run can never
 *      re-queue the same entry twice (it is already gone from the DLQ).
 *   2. Reset the audit row to 'received' (requeueFromDlq) BEFORE re-pushing — identical rule to
 *      RetryScheduler::deadLetter/retryOrFail ("DB write first"): if the push then fails, the row
 *      is left at the recoverable 'received' (visible on the dashboard, re-pushable), never an
 *      envelope-on-the-queue pointing at a 'failed' row the worker would silently skip.
 *   3. Re-enqueue a FRESH attempt=1 envelope LAST — reusing Queue::enqueue (no new push method),
 *      which already throws loudly on a failed RPUSH so a half-moved job surfaces.
 * The worst crash residue is therefore "envelope popped, row still 'failed'" (the same tolerable
 * class as ADR 0014's "failed row, no DLQ entry"): the row honestly reads 'failed' on the
 * dashboard and can be re-driven — strictly better than the dangerous live-envelope-over-failed-row
 * orphan, which this ordering makes impossible.
 *
 * Idempotent and resumable under at-least-once: requeueFromDlq's `AND status='failed'` guard and
 * the worker's `markProcessing` `AND status='received'` guard compose so a stray duplicate is a
 * no-op, and LPOP-per-entry means a re-run finishes a crashed drain.
 */
final class DlqRequeue
{
    public function __construct(
        private readonly Queue $queue,
        private readonly EventRepository $events,
    ) {
    }

    /**
     * Drain every entry currently on the dead-letter list back onto the main queue.
     *
     * The loop is BOUNDED by the DLQ size read ONCE at the start (ADR 0017, Decision 2/3): we pop
     * at most that many entries, so a worker that re-dead-letters a still-broken event during the
     * drain cannot live-lock us into an unbounded loop — the re-DLQ'd entry simply waits for the
     * next run. Each pop also strictly shrinks the list, so the drain terminates regardless. The
     * snapshot drains FIFO (LPOP head, the worker only RPUSHes the tail), so we replay the original
     * backlog, oldest first.
     *
     * Per entry, in the safe order above: pop → reset the row → re-enqueue. An entry whose row is
     * absent or no longer 'failed' (requeueFromDlq returns false) is NOT re-enqueued — there is
     * nothing claimable to re-drive — and is counted as `skipped`; its envelope is already off the
     * DLQ, which is correct (a dangling envelope for a missing/processed row should not linger). A
     * genuinely corrupt envelope surfaces QueueException::decodeFailed from the pop (the entry is
     * already LPOP'd, so it is consumed); we count it as `corrupt` and continue, so one poison entry
     * cannot abort the whole drain.
     *
     * $onEntry, if given, is invoked once per consumed entry as ($outcome, ?Job) where $outcome is
     * 'requeued' | 'skipped' | 'corrupt' (Job is null only for 'corrupt', which never decoded), so
     * the CLI can stream a line per event WITHOUT this policy class doing any I/O of its own.
     *
     * A QueueException::enqueueFailed / requeueOneFromDeadLetter connection fault, or a
     * PDOException from requeueFromDlq, is an INFRA fault: it propagates to the CLI's typed-error
     * backstop (exit 1), exactly as bin/migrate.php lets a DatabaseException propagate — we do not
     * swallow a down dependency mid-drain.
     */
    public function drainAll(?Closure $onEntry = null): DlqRequeueReport
    {
        $remaining = $this->queue->deadLetterSize();

        $requeued = 0;
        $skipped = 0;
        $corrupt = 0;

        for ($i = 0; $i < $remaining; $i++) {
            try {
                $job = $this->queue->requeueOneFromDeadLetter();
            } catch (QueueException) {
                // A poison envelope: the LPOP succeeded (the entry is consumed) but it could not be
                // decoded. Count it and keep draining rather than aborting the run on one bad entry.
                $corrupt++;
                $onEntry?->__invoke('corrupt', null);
                continue;
            }

            if ($job === null) {
                // The DLQ emptied before we reached the snapshot count (e.g. nothing else is a
                // writer at human scale, but be defensive) — nothing left to drain.
                break;
            }

            if (!$this->events->requeueFromDlq($job->provider(), $job->eventId())) {
                // No row, or a row not in 'failed' (already re-driven / a stale envelope). Nothing
                // claimable to re-process, so do NOT re-enqueue; the envelope is already popped.
                $skipped++;
                $onEntry?->__invoke('skipped', $job);
                continue;
            }

            // Row re-armed to 'received' — NOW it is safe to put the work back on the live queue,
            // with a fresh attempt=1 envelope so the replay gets a full 3-attempt budget.
            $this->queue->enqueue($job->eventId(), $job->provider(), 1);
            $requeued++;
            $onEntry?->__invoke('requeued', $job);
        }

        return new DlqRequeueReport($requeued, $skipped, $corrupt);
    }
}
