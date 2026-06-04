<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\DatabaseConfig;
use App\Exception\DatabaseException;
use PDO;
use PDOException;

/**
 * Builds a configured PDO connection from the application config.
 *
 * Deliberately a thin factory, not a delegating wrapper: the project rule is to
 * avoid abstractions that have a single implementation. Callers receive a plain
 * \PDO and use prepared statements directly.
 */
final class Connection
{
    public static function fromConfig(DatabaseConfig $config): PDO
    {
        try {
            return new PDO(
                $config->dsn(),
                $config->username(),
                $config->password(),
                [
                    // Loud failures: every DB error throws instead of returning false.
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    // Real server-side prepared statements (defence against SQL injection).
                    PDO::ATTR_EMULATE_PREPARES => false,
                    // Associative rows by default.
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        } catch (PDOException $e) {
            // Rethrow without the password: $config->password() is never put in the message.
            throw DatabaseException::connectionFailed($config->host(), $config->name(), $e);
        }
    }
}
