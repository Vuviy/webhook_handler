-- 0001_create_webhook_events
--
-- The full audit log of every webhook the system has seen (spec FR-9, data model
-- `webhook-handler.spec.md:64`). One row per delivery, written at ingestion with
-- status=received and mutated by the worker as it processes.
--
-- One statement per file (ADR 0004): MySQL DDL auto-commits, so we keep exactly
-- one CREATE here and use IF NOT EXISTS so a retry after a partial apply is safe.
--
-- The composite UNIQUE (provider, event_id) is the dedup / idempotency backbone:
-- delivery is at-least-once, so a duplicate POST for the same provider event must
-- not create a second row (enables INSERT ... ON DUPLICATE KEY in T1.5).
CREATE TABLE IF NOT EXISTS webhook_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider        VARCHAR(32)     NOT NULL,
    event_id        VARCHAR(255)    NOT NULL,
    event_type      VARCHAR(128)    NOT NULL,
    payload         LONGTEXT        NOT NULL,
    signature_valid TINYINT(1)      NOT NULL DEFAULT 0,
    status          ENUM('received', 'processing', 'processed', 'failed') NOT NULL DEFAULT 'received',
    attempts        INT UNSIGNED    NOT NULL DEFAULT 0,
    last_error      TEXT            NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    processed_at    TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_provider_event (provider, event_id),
    KEY idx_status (status),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
