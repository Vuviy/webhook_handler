<?php

declare(strict_types=1);

/**
 * The long-running queue worker (T2.2).
 *
 * Usage (its own container; docker-compose runs exactly this):
 *   php worker.php          # command: ["php", "worker.php"], restart: unless-stopped
 *
 * This is the asynchronous half of the system. The HTTP path only verifies, records and
 * enqueues (CLAUDE.md: "the HTTP request must never do the heavy work"); ALL real
 * processing happens here. Each loop iteration:
 *   1. BLPOP a job off webhooks:queue (a tiny {event_id, provider, attempt} reference),
 *   2. read the matching webhook_events row ONCE and CLAIM it (received → processing),
 *   3. dispatch to the provider's handler via HandlerRegistry,
 *   4. success → processed; failure → failed (interim — see below).
 *
 * Lives at the app root (above public/), so it is never reachable over HTTP — the worker
 * is an operator-run process, not a request.
 *
 * SCOPE (T2.2) and DOCUMENTED DEBT (ADR 0012, Decision 3): there is no retry queue (T2.3)
 * and no dead-letter queue (T2.4) yet. So in this task EVERY handler failure — transient,
 * permanent, or an unforeseen Throwable — is marked terminally 'failed' with last_error.
 * That over-commits a transient fault that T2.3 would later retry; it is honest, logged
 * and audited, not silent. The three handler-failure catch blocks below are the exact
 * seams T2.3/T2.4 will fill: T2.3 reroutes the transient/catch-all branches to "schedule
 * retry / mark received", T2.4 reroutes the permanent (and exhausted) branch to the DLQ.
 * Graceful SIGTERM shutdown is T2.5 — its natural home is the `$job === null` idle tick.
 *
 * Resilience: connections are built ONCE before the loop, so a startup outage fails fast
 * at boot (like bin/migrate.php). Inside the loop, an INFRA fault (Redis BLPOP throws, or
 * a DB write fails mid-iteration) is caught by the outer backstop, logged, and followed by
 * a short fixed sleep before continuing — so a flapping dependency degrades to retry-the-
 * loop instead of a hot crash-loop under `restart: unless-stopped`. That sleep is INFRA
 * backoff and is explicitly NOT the T2.3 retry backoff.
 */

use App\Config\Config;
use App\Database\Connection;
use App\Exception\PermanentHandlerException;
use App\Exception\TransientHandlerException;
use App\Handler\HandlerRegistry;
use App\Handler\WebhookEvent;
use App\Queue\Queue;
use App\Repository\EventRepository;

/**
 * BLPOP block time. The loop wakes at least this often even when idle, giving T2.5 a place
 * to check a shutdown flag and the loop a chance to notice a dropped connection.
 */
const BLPOP_TIMEOUT_SECONDS = 5;

/**
 * Fixed pause after an infra fault so a down dependency does not busy-spin the loop. INFRA
 * backoff only — not the exponential retry backoff of T2.3.
 */
const INFRA_BACKOFF_SECONDS = 1;

/** @var Config $config */
$config = require __DIR__ . '/bootstrap.php';

// Build every dependency once, up front. If MySQL or Redis is unreachable at boot, the
// factory throws here and the container exits loudly — exactly the fail-fast we want
// before entering the loop (mirrors bin/migrate.php).
$queue = Queue::fromConfig($config->redis());
$events = new EventRepository(Connection::fromConfig($config->database()));
$registry = new HandlerRegistry();

fwrite(STDOUT, "worker: started, waiting for jobs on webhooks:queue\n");

while (true) {
    try {
        $job = $queue->consume(BLPOP_TIMEOUT_SECONDS);

        if ($job === null) {
            // Idle tick: no job within the timeout. T2.5's graceful-shutdown check lands here.
            continue;
        }

        $row = $events->findForProcessing($job->provider(), $job->eventId());

        if ($row === null) {
            // At-least-once: a job referencing a row that was never written or has gone.
            // Nothing to process — log and move on rather than crash.
            error_log(sprintf(
                'worker: no row for %s event "%s"; skipping',
                $job->provider(),
                $job->eventId(),
            ));
            continue;
        }

        // CLAIM the row (received → processing). If the guard matches 0 rows the event was
        // already claimed/processed by an earlier delivery of this same job — skip it
        // (worker-level dedupe, FR-11) without re-running the handler.
        if (!$events->markProcessing($row['id'])) {
            continue;
        }

        $handler = $registry->get($job->provider());

        if ($handler === null) {
            // A job for a provider we have no handler for cannot succeed by retrying — it is
            // a permanent fault. (Ingestion only enqueues known providers, so this is a
            // defensive guard, not an expected path.)
            $events->markFailed($row['id'], sprintf('no handler for provider "%s"', $job->provider()));
            continue;
        }

        $event = new WebhookEvent(
            $job->provider(),
            $job->eventId(),
            $row['event_type'],
            $row['payload'],
            $job->attempt(),
        );

        // ONLY the handler call is wrapped: a throw here is a HANDLER failure, mapped to
        // markFailed. The markProcessed write is deliberately OUTSIDE this try — if it
        // failed it would be an INFRA fault, and catching it here would wrongly record a
        // successfully-handled event as 'failed' (corrupting the audit trail and, since the
        // job is gone from the queue, never reaching 'processed'). An infra failure of the
        // status write instead falls through to the outer backstop, leaving the row in
        // 'processing' — honest, and the stuck-'processing' reaper is future work.
        try {
            $handler->handle($event);
        } catch (TransientHandlerException $e) {
            // SEAM for T2.3: schedule a retry (mark 'received', requeue with backoff) while
            // attempts remain. Interim: marked 'failed'.
            $events->markFailed($row['id'], $e->getMessage());
            continue;
        } catch (PermanentHandlerException $e) {
            // SEAM for T2.4: straight to webhooks:dlq, no retry. Interim: marked 'failed'.
            $events->markFailed($row['id'], $e->getMessage());
            continue;
        } catch (\Throwable $e) {
            // Any OTHER Throwable from the handler is treated as transient (ADR 0011): an
            // unforeseen bug should be retried, not silently dropped. SEAM for T2.3.
            // Interim: marked 'failed'.
            $events->markFailed($row['id'], $e->getMessage());
            continue;
        }

        // handle() returned normally = SUCCESS. A failure of this write is an infra fault
        // (outer backstop), never a handler failure — so a succeeded event is never
        // mis-recorded as 'failed'.
        $events->markProcessed($row['id']);
    } catch (\Throwable $e) {
        // Infra fault (Redis BLPOP / a status write failing mid-iteration). Log with the
        // front controller's discipline — full detail to the error log, never leaked
        // onward — then back off briefly so a down dependency does not busy-spin.
        error_log((string) $e);
        sleep(INFRA_BACKOFF_SECONDS);
    }
}
