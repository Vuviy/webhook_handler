<?php

declare(strict_types=1);

/**
 * CLI migration runner.
 *
 * Usage (inside the PHP container):
 *   docker compose exec php php bin/migrate.php
 *
 * Boots the autoloader + config like every other entry point, opens a PDO, then
 * applies every pending `app/migrations/*.sql` file in filename order. Prints one
 * line per applied file; exits 0 on success, 1 (with a typed DatabaseException to
 * STDERR) on failure. A second run with nothing pending prints "up to date".
 *
 * Lives in bin/ (above the Nginx web root in public/), so it is never reachable
 * over HTTP — migrations are an operator action, not a request.
 */

use App\Config\Config;
use App\Database\Connection;
use App\Database\MigrationRunner;
use App\Exception\DatabaseException;

/** @var Config $config */
$config = require __DIR__ . '/../bootstrap.php';

try {
    $pdo = Connection::fromConfig($config->database());
    $runner = new MigrationRunner($pdo, __DIR__ . '/../migrations');

    $applied = $runner->run();

    if ($applied === []) {
        fwrite(STDOUT, "Database is up to date — no migrations to apply.\n");
    } else {
        foreach ($applied as $migration) {
            fwrite(STDOUT, sprintf("Applied: %s\n", $migration));
        }
        fwrite(STDOUT, sprintf("Done — %d migration(s) applied.\n", count($applied)));
    }

    exit(0);
} catch (DatabaseException $e) {
    fwrite(STDERR, sprintf("Migration error: %s\n", $e->getMessage()));
    exit(1);
}
