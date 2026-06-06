<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Persistence for the webhook_events audit log. Owns the dedupe-insert that makes
 * ingestion idempotent under at-least-once delivery (FR-11, AC-6).
 *
 * The dedupe is the database's job, not the application's: a SELECT-then-INSERT would
 * race two concurrent identical re-deliveries (both SELECT "absent", both INSERT). We
 * lean on UNIQUE(provider, event_id) and a single atomic statement instead.
 */
final class EventRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Record a VERIFIED event as status='received'. Returns true if THIS call inserted
     * a brand-new row (the caller should enqueue it), false if the event was already
     * present (a re-delivery — the caller must NOT enqueue again, but still answers 2xx).
     *
     * `ON DUPLICATE KEY UPDATE id = id` is a deliberate no-op: it turns the unique-key
     * collision into "do nothing" rather than an error, while keeping MySQL's
     * affected-rows count meaningful — 1 for a real insert, 0 for the no-op on a
     * duplicate (it would be 2 for an actual UPDATE, which we never perform). That is
     * precisely why rowCount() === 1 means "newly inserted". Do not "simplify" the
     * no-op away. We never use INSERT IGNORE here: it would also swallow genuine errors
     * (truncation, NOT NULL violations) silently, and this is an audit table.
     *
     * signature_valid is hardcoded to 1 because only VALID events are ever inserted
     * (invalid/forged requests are rejected with no row — ADR 0009). The payload is the
     * RAW request bytes, never re-encoded JSON, so the stored audit copy is byte-exact
     * with what was signed.
     */
    public function insertReceived(
        string $provider,
        string $eventId,
        string $eventType,
        string $rawPayload,
    ): bool {
        $statement = $this->pdo->prepare(
            'INSERT INTO webhook_events (provider, event_id, event_type, payload, signature_valid, status)
             VALUES (:provider, :event_id, :event_type, :payload, 1, \'received\')
             ON DUPLICATE KEY UPDATE id = id',
        );

        // execute()'s return is not checked because the PDO is built with
        // ERRMODE_EXCEPTION (Connection.php), so any failure throws a PDOException
        // rather than returning false — the controller catches that and maps it to 500.
        $statement->execute([
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => $rawPayload,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Read the persisted row for one event so the worker can build a WebhookEvent and
     * drive its status. Returns the associative row, or null if no such row exists.
     *
     * A null is a real possibility under at-least-once delivery: a job can be popped for
     * an event whose row was never written or has since gone — the worker treats that as
     * "nothing to do" and skips, rather than crashing (ADR 0012, Decision 3). Keyed by the
     * (provider, event_id) UNIQUE so it reads exactly the row ingestion inserted.
     *
     * @return array{id: int, event_type: string, payload: string, status: string}|null
     */
    public function findForProcessing(string $provider, string $eventId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, event_type, payload, status
             FROM webhook_events
             WHERE provider = :provider AND event_id = :event_id',
        );
        $statement->execute(['provider' => $provider, 'event_id' => $eventId]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'event_type' => (string) $row['event_type'],
            'payload' => (string) $row['payload'],
            'status' => (string) $row['status'],
        ];
    }

    /**
     * Atomically CLAIM an event for processing: received → processing. Returns true only
     * if THIS call won the claim (the row was still 'received'), false otherwise.
     *
     * The `AND status = 'received'` guard is the worker-level dedupe for at-least-once
     * delivery (FR-11): if the same job is popped twice, only the first claim matches a
     * row; the second updates 0 rows, returns false, and the worker skips it without
     * re-running the handler — no second lookup needed (ADR 0012, Decision 2). It is also
     * already correct for T2.3's retry, which puts a job back at 'received' before requeue,
     * so a legitimate retry wins the claim again.
     *
     * Only `status` is set: `updated_at` is the column's `ON UPDATE CURRENT_TIMESTAMP`
     * (migration 0001), so MySQL bumps it automatically whenever the row actually changes.
     * No explicit `updated_at = NOW()` is needed (it would be redundant).
     */
    public function markProcessing(int $id): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE webhook_events
             SET status = \'processing\'
             WHERE id = :id AND status = \'received\'',
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    /**
     * Mark a successfully handled event processed and stamp processed_at (NOW()), so the
     * audit log records WHEN processing completed (FR-9). Terminal happy-path state.
     */
    public function markProcessed(int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE webhook_events
             SET status = \'processed\', processed_at = NOW()
             WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
    }

    /**
     * Schedule a transiently-failed event for another attempt (T2.3): set status BACK to
     * 'received' so the guarded markProcessing() claim can win again once the retry is
     * promoted off the zset, record the new attempt count, and persist the reason in
     * last_error so the dashboard can show why the previous try failed.
     *
     * Writing $attempts here is the DB audit MIRROR of the envelope's incremented attempt
     * (ADR 0013, Decision 3): both receive the SAME number from the single increment in
     * RetryScheduler::retryOrFail(), so the dashboard (FR-9) shows the true try count while
     * the envelope stays the control value on the wire.
     *
     * No status guard is needed: the worker holds the row in 'processing' (it just won the
     * claim), so this is its own transition to make. processed_at is deliberately left
     * untouched — a retry has NOT completed; only markProcessed() ever stamps it.
     */
    public function markForRetry(int $id, int $attempts, string $error): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE webhook_events
             SET status = \'received\', attempts = :attempts, last_error = :error
             WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'attempts' => $attempts, 'error' => $error]);
    }

    /**
     * Mark an event failed and persist the human-readable reason in last_error (FR-9,
     * NFR-4: every failure is visible to the dashboard).
     *
     * As of T2.3, a transient fault no longer lands here while attempts remain — it is
     * rerouted to markForRetry()/scheduleRetry(). Only two cases reach markFailed: a PERMANENT
     * error, and a transient one whose attempts are EXHAUSTED. As of T2.4 both call sites go
     * through RetryScheduler::deadLetter(), which calls THIS method FIRST (marking the row
     * terminally 'failed' with last_error) and then pushes the envelope to webhooks:dlq — so
     * markFailed is the DB half of a terminal pair, not interim debt. The DB-first ordering
     * means a 'failed' row is always recorded even if the DLQ push later fails (ADR 0014).
     */
    public function markFailed(int $id, string $error): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE webhook_events
             SET status = \'failed\', last_error = :error
             WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'error' => $error]);
    }
}
