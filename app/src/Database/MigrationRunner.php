<?php

declare(strict_types=1);

namespace App\Database;

use App\Exception\DatabaseException;
use PDO;
use PDOException;

/**
 * Minimal forward-only migration runner.
 *
 * Applies plain `.sql` files from a directory in filename order and records each
 * applied file in a `schema_migrations` table, so a second run is a no-op driven
 * by recorded data (not by guessing with IF NOT EXISTS).
 *
 * MySQL DDL is NOT transactional (each DDL statement auto-commits), so we cannot
 * wrap a migration in a rollback-able transaction. Instead we (a) record the
 * migration row only AFTER the DDL succeeds — a crash before recording leaves the
 * file pending and it is retried — and (b) keep one statement per file plus
 * idempotent DDL so a retry after a partial apply does not hard-fail.
 */
final class MigrationRunner
{
    private const MIGRATIONS_TABLE = 'schema_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
    ) {
    }

    /**
     * @return list<string> filenames applied during this run (empty when up to date)
     */
    public function run(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->appliedMigrations();

        $files = glob(rtrim($this->migrationsPath, '/') . '/*.sql');
        if ($files === false) {
            // glob() fails on an unreadable/missing directory — surface it loudly
            // rather than masking it as "nothing to apply" (which would look like
            // an up-to-date database).
            throw DatabaseException::migrationsPathUnreadable($this->migrationsPath);
        }
        sort($files);

        $justApplied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            $this->apply($name, $file);
            $justApplied[] = $name;
        }

        return $justApplied;
    }

    /**
     * The single sanctioned use of CREATE TABLE IF NOT EXISTS outside a numbered
     * migration — it bootstraps the tracking table itself.
     */
    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::MIGRATIONS_TABLE . ' ('
            . 'migration VARCHAR(255) NOT NULL,'
            . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (migration)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /**
     * @return list<string>
     */
    private function appliedMigrations(): array
    {
        $stmt = $this->pdo->query('SELECT migration FROM ' . self::MIGRATIONS_TABLE);

        /** @var list<string> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $rows;
    }

    private function apply(string $name, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw DatabaseException::migrationFailed($name);
        }

        try {
            $this->pdo->exec($sql);

            // Record only AFTER the DDL succeeds (DDL auto-commits; no transaction to rely on).
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::MIGRATIONS_TABLE . ' (migration) VALUES (?)',
            );
            $stmt->execute([$name]);
        } catch (PDOException $e) {
            throw DatabaseException::migrationFailed($name, $e);
        }
    }
}
