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
final class EventRepository implements EventWriter
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

    /**
     * Re-arm a dead-lettered row so it can be processed again (T3.2): set it BACK to
     * 'received', reset attempts to 0 and clear last_error. Returns true iff THIS call reset
     * exactly such a row, false otherwise.
     *
     * The `AND status = 'failed'` guard is the safety latch (ADR 0017, Decision 4): only a
     * genuinely dead-lettered row is ever re-armed. A row that is absent, or already
     * 'received'/'processing'/'processed' (e.g. a re-run of the drain, or a DLQ envelope whose
     * row was meanwhile re-driven), matches 0 rows and returns false — a harmless no-op, never a
     * corruption of a live or completed event. It is the recovery-direction counterpart of
     * markFailed(): markFailed drives received→failed, this drives failed→received.
     *
     * Resetting `attempts = 0` is load-bearing, not cosmetic: the DLQ envelope carried the
     * exhausted attempt count, so without this the re-queued job would compute next > MAX_ATTEMPTS
     * and bounce straight back to the DLQ on its first new failure (ADR 0017, Context). Zeroing it
     * here — paired with the fresh attempt=1 envelope the orchestrator re-enqueues — gives the
     * replay a full, clean 3-attempt budget and keeps the DB audit mirror coherent with the wire
     * envelope (the same coherence rule as markForRetry, ADR 0013 D3).
     *
     * `last_error` is cleared to NULL so a successfully re-queued event drops off the dashboard's
     * recent-failures panel (recentFailures() reads status='failed'): once it is back on the live
     * path it is no longer a current failure. processed_at is left untouched — a re-queue has not
     * completed; only markProcessed() ever stamps it.
     */
    public function requeueFromDlq(string $provider, string $eventId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE webhook_events
             SET status = \'received\', attempts = 0, last_error = NULL
             WHERE provider = :provider AND event_id = :event_id AND status = \'failed\'',
        );
        $statement->execute(['provider' => $provider, 'event_id' => $eventId]);

        return $statement->rowCount() === 1;
    }

    /**
     * Count rows grouped by status, for the monitoring dashboard (FR-10, AC-5). Returns a
     * map keyed by status with EVERY enum value present and defaulted to 0, so a status with
     * no rows yet still reports 0 rather than being absent — a stable JSON contract the
     * dashboard (and any future static front) can rely on.
     *
     * One grouped query, not four COUNTs nor a fetch-and-count in PHP: the GROUP BY is served
     * by idx_status (migration 0001), so it scans the index, never the table or the LONGTEXT
     * payload. The PHP-side normalisation only fills in the zero buckets the query legitimately
     * omits (a status with no rows produces no group), it does not re-count anything.
     *
     * @return array{received: int, processing: int, processed: int, failed: int}
     */
    public function countsByStatus(): array
    {
        // Pre-seed all four enum buckets to 0 so the shape is complete and ordered even before
        // the query; the loop below overwrites only the statuses that actually have rows.
        $counts = ['received' => 0, 'processing' => 0, 'processed' => 0, 'failed' => 0];

        $statement = $this->pdo->query(
            'SELECT status, COUNT(*) AS total
             FROM webhook_events
             GROUP BY status',
        );

        foreach ($statement as $row) {
            $status = (string) $row['status'];

            // Guard against an unexpected status value (e.g. a future enum member added by a
            // later migration but not yet known here): only fill buckets we declared, so a
            // stray status never injects an unkeyed entry into the stable contract.
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * The most recently failed events, newest first, for the dashboard's "recent failures"
     * panel (FR-10, AC-5). Ordered by updated_at DESC because that column is the moment the
     * row went terminal (it carries ON UPDATE CURRENT_TIMESTAMP — migration 0001 — and
     * markFailed() deliberately does not set it explicitly), so "most recent failure first"
     * is exactly the operator's view.
     *
     * Column safety (NFR-1): the SELECT list is PINNED to safe metadata only — the raw
     * `payload` LONGTEXT (the signed request bytes) is NEVER selected, so it cannot leak into
     * the response even by accident. last_error and attempts ARE included: they are precisely
     * what "recent failures" exists to show, and last_error is the human reason the worker
     * already chose to persist for this view (FR-9).
     *
     * $limit is bound as PARAM_INT on purpose: the PDO is built with ATTR_EMULATE_PREPARES =>
     * false (Connection.php), so a *string*-bound LIMIT would be sent quoted and MySQL would
     * reject it. The caller passes a fixed constant (no user input), but the integer bind keeps
     * the query correct regardless.
     *
     * @return list<array{
     *     id: int, provider: string, event_type: string, attempts: int,
     *     last_error: ?string, created_at: string, updated_at: string
     * }>
     */
    public function recentFailures(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, provider, event_type, attempts, last_error, created_at, updated_at
             FROM webhook_events
             WHERE status = \'failed\'
             ORDER BY updated_at DESC
             LIMIT :limit',
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $failures = [];

        foreach ($statement as $row) {
            $failures[] = [
                'id' => (int) $row['id'],
                'provider' => (string) $row['provider'],
                'event_type' => (string) $row['event_type'],
                'attempts' => (int) $row['attempts'],
                // last_error is a nullable TEXT column; preserve null rather than coercing to ''.
                'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $failures;
    }
}
