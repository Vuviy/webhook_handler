<?php

declare(strict_types=1);

/**
 * Front controller — the single entry point of the application.
 *
 * Nginx points its document root at this `public/` directory and funnels every
 * request here (`try_files … /index.php`), so nothing else in `app/` is reachable
 * over HTTP. This script stays thin on purpose: it parses the request, routes two
 * URLs, hands a webhook POST to the (pure, testable) IngestionController, renders the
 * result, and guarantees no Throwable ever escapes into the response (NFR-1).
 *
 * Routing (path × method):
 *   GET  /                       → 200 health JSON
 *   *    /                       → 405 (Allow: GET)
 *   POST /webhooks/{provider}    → IngestionController → 202 / 401 / 502 / 500 / 404
 *   *    /webhooks/{provider}    → 405 (Allow: POST)
 *   *    /webhooks | /webhooks/  → 400 (malformed: no provider segment)
 *   *    anything else           → 404
 */

use App\Config\Config;
use App\Database\Connection;
use App\Health;
use App\Http\IngestionController;
use App\Http\IngestionResponse;
use App\Http\ServerRequest;
use App\Queue\Queue;
use App\Repository\EventRepository;
use App\Verification\VerifierRegistry;

/** @var Config $config */
$config = require __DIR__ . '/../bootstrap.php';

// Cheap early-out for browser favicon probes, before any routing.
if (($_SERVER['REQUEST_URI'] ?? '') === '/favicon.ico') {
    return;
}

// Read the raw body explicitly: a body of literally "0" is falsy, so `?: ''` would
// wrongly drop it. Only an outright false (no body) becomes the empty string.
$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$request = ServerRequest::fromGlobals($_SERVER, $rawBody);

// Global backstop: building the controller can throw (DatabaseException /
// QueueException from the factories when MySQL/Redis is down, BEFORE handle() runs),
// and so could any unforeseen bug. Convert ANY Throwable into a clean 500 whose body
// carries no message, trace or payload — full detail goes to the error log only.
try {
    [$status, $body, $allow] = dispatch($request, $config);
} catch (\Throwable $e) {
    // Full trace to the server error log only (never the response body — NFR-1). The
    // trace is what makes a DB/Redis-outage 500 diagnosable. Guard: code on the
    // build/handle path must NEVER put a raw payload or secret into an exception
    // message, or it would land here — none does today (verifiers/factories don't).
    error_log((string) $e);
    [$status, $body, $allow] = [500, '{"status":"error"}', null];
}

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
if ($allow !== null) {
    header('Allow: ' . $allow);
}
if ($body !== null) {
    echo $body;
}

/**
 * Route the request to a (status, body, allow-header) triple. Kept as a function so
 * the whole dispatch — including the per-request construction of the controller and
 * its DB/Redis connections — sits inside the caller's try/catch backstop.
 *
 * The controller (and its connections) is built ONLY on the matched webhook POST, so
 * health / 400 / 404 / 405 open no MySQL or Redis connection — a Redis outage must not
 * break a health check.
 *
 * @return array{0: int, 1: ?string, 2: ?string} [statusCode, body, Allow header or null]
 */
function dispatch(ServerRequest $request, Config $config): array
{
    $method = $request->method();
    $path = $request->path();

    // Health endpoint.
    if ($path === '/') {
        if ($method !== 'GET') {
            return [405, IngestionResponse::rejected(405)->body(), 'GET'];
        }

        return [200, json_encode((new Health())->status(), JSON_THROW_ON_ERROR), null];
    }

    // The webhook namespace addressed with no provider → a MALFORMED url (400),
    // distinct from a well-formed url naming an unknown provider (the controller's 404).
    if ($path === '/webhooks' || $path === '/webhooks/') {
        return [400, IngestionResponse::rejected(400)->body(), null];
    }

    // POST /webhooks/{provider}: a single, non-empty segment. One trailing slash is
    // tolerated; a deeper sub-path (/webhooks/github/x) does not match and falls to 404.
    if (preg_match('#^/webhooks/([^/]+)$#', rtrim($path, '/'), $matches) === 1) {
        if ($method !== 'POST') {
            return [405, IngestionResponse::rejected(405)->body(), 'POST'];
        }

        $controller = new IngestionController(
            new VerifierRegistry($config->providers()),
            new EventRepository(Connection::fromConfig($config->database())),
            Queue::fromConfig($config->redis()),
        );

        // Lower-case the provider tag so /webhooks/GitHub works; the controller maps an
        // unknown (but well-formed) provider to 404 itself — we do not pre-filter here.
        $response = $controller->handle(strtolower($matches[1]), $request->rawBody(), $request->headers());

        return [$response->statusCode(), $response->body(), null];
    }

    return [404, IngestionResponse::rejected(404)->body(), null];
}
