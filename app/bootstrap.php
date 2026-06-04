<?php

declare(strict_types=1);

/**
 * Shared bootstrap for every entry point (HTTP front controller and, later, the
 * queue worker). It does three things and nothing more:
 *   1. register the Composer autoloader,
 *   2. load app/.env into the environment (immutable: never overrides values
 *      already injected by Docker compose),
 *   3. build and return the validated Config object.
 *
 * Sits above the Nginx web root (public/), so it is not reachable over HTTP.
 */

use App\Config\Config;
use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

Dotenv::createImmutable(__DIR__)->safeLoad();

return Config::fromEnv();
