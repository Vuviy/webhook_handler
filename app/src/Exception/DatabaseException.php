<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown on database connection or migration failures.
 *
 * Messages name the host / database / migration file, but NEVER the password or
 * the full DSN credentials, so secrets cannot leak into logs or stack traces.
 */
final class DatabaseException extends RuntimeException
{
    public static function connectionFailed(string $host, string $database, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to connect to database "%s" on host "%s".', $database, $host),
            0,
            $previous,
        );
    }

    public static function migrationFailed(string $migration, ?Throwable $previous = null): self
    {
        return new self(sprintf('Migration "%s" failed.', $migration), 0, $previous);
    }

    public static function migrationsPathUnreadable(string $path): self
    {
        return new self(sprintf('Migrations directory "%s" is missing or unreadable.', $path));
    }
}
