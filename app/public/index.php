<?php

declare(strict_types=1);

/**
 * Front controller — the single entry point of the application.
 *
 * Web server (Nginx) points its document root at this `public/` directory, so
 * nothing else in `app/` (source, vendor, .env, composer files) is reachable
 * over HTTP. Every request is funnelled through this file.
 *
 * T0.1 scope: only boot the autoloader and return a verifiable health response.
 * Routing (T1.6), config (T0.2), DB (T0.3) and the queue (T0.4) come later and
 * will be wired in here.
 */

use App\Config\Config;
use App\Health;

/** @var Config $config */
$config = require __DIR__ . '/../bootstrap.php';

//to prevent double request
if ($_SERVER['REQUEST_URI'] === '/favicon.ico') {
    return;
}
$health = new Health();

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

echo json_encode($health->status(), JSON_THROW_ON_ERROR);
