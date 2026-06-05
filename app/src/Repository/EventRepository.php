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
}
