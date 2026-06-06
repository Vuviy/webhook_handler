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
 * SCOPE (as of T2.4) and REMAINING DEBT: both failure seams are now FILLED. A transient
 * handler failure (TransientHandlerException or any other Throwable) is routed through
 * RetryScheduler::retryOrFail — while attempts remain it is scheduled on the webhooks:retry
 * zset with exponential, jittered backoff and the row goes back to 'received'; promoteDue()
 * at the top of the loop moves due retries back onto the main queue (ADR 0013). A PERMANENT
 * error, and a transient one whose attempts are EXHAUSTED, now go to RetryScheduler::deadLetter
 * — the row is marked terminally 'failed' with last_error AND the envelope is pushed to
 * webhooks:dlq (ADR 0014). Graceful SIGTERM/SIGINT shutdown is now wired (T2.5, ADR 0015): a
 * flag-only signal handler lets the in-flight job finish, then the loop exits 0 instead of
 * being SIGKILLed mid-handler. Remaining DEBT: the DLQ drain / re-queue path (T3.2). The
 * pre-existing stuck-'processing' reaper (a row orphaned by an infra fault on a status write)
 * is still future work.
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
use App\Queue\RetryScheduler;
use App\Repository\EventRepository;

/**
 * BLPOP block time. The loop wakes at least this often even when idle, giving the loop a
 * chance to notice a dropped connection. It also BOUNDS the worst-case graceful-shutdown
 * latency: phpredis does not abort BLPOP when a signal arrives mid-call, so a stop requested
 * during an idle block is acted on when BLPOP next returns — i.e. within this many seconds,
 * comfortably inside the container stop-grace window.
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
$scheduler = new RetryScheduler($queue);

// T2.5 graceful shutdown (ADR 0015). The signal handler does ONE thing — set a flag — so the
// in-flight iteration finishes naturally before the loop exits; it never throws or exits from
// within the handler, which would abandon a half-processed job. pcntl_async_signals(true) lets
// the handler run as soon as PHP regains control; phpredis does NOT abort BLPOP on the
// interrupt, so the flag is honoured when BLPOP next returns (≤ BLPOP_TIMEOUT_SECONDS) — still
// well inside the stop-grace window.
//
// We listen on THREE signals: SIGTERM (what we pin the worker service to stop with), SIGINT
// (local Ctrl-C), and SIGQUIT. SIGQUIT matters specifically here: the php-fpm base image sets
// STOPSIGNAL=SIGQUIT (the graceful signal for php-fpm itself), which this worker image
// inherits — so `docker stop` on the raw image would deliver SIGQUIT. Handling it too means
// the worker winds down cleanly no matter which of those a stop arrives as, rather than being
// SIGKILLed after the grace period. (docker-compose.yml also pins stop_signal: SIGTERM.)
$shouldStop = false;
pcntl_async_signals(true);
$onSignal = static function (int $signal) use (&$shouldStop): void {
    $shouldStop = true;
};
pcntl_signal(SIGTERM, $onSignal);
pcntl_signal(SIGINT, $onSignal);
pcntl_signal(SIGQUIT, $onSignal);

fwrite(STDOUT, "worker: started, waiting for jobs on webhooks:queue\n");

// `while (!$shouldStop)` is the load-bearing exit: a signal that arrives mid-job sets the flag,
// the current iteration runs to completion (handler + status write), then the condition is
// re-checked before the next job is ever claimed — so we never abandon work in flight.
while (!$shouldStop) {
    try {
        // T2.3: move any now-due retries from webhooks:retry back onto the main queue before
        // blocking, so a scheduled retry is re-popped within one loop turn (ADR 0013, D1).
        $scheduler->promoteDue();

        $job = $queue->consume(BLPOP_TIMEOUT_SECONDS);

        if ($job === null) {
            // Idle tick: no job within the timeout (or BLPOP was interrupted by a signal). Loop
            // back so the `while (!$shouldStop)` condition can exit promptly if a stop was asked.
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
            // defensive guard, not an expected path.) Route it through the SAME terminal path
            // as a PermanentHandlerException (T2.4): mark 'failed' with last_error AND push to
            // webhooks:dlq, so every terminal failure is uniformly dead-lettered (ADR 0014).
            $scheduler->deadLetter($events, $row['id'], $job, sprintf('no handler for provider "%s"', $job->provider()));
            continue;
        }

        $event = new WebhookEvent(
            $job->provider(),
            $job->eventId(),
            $row['event_type'],
            $row['payload'],
            $job->attempt(),
        );

        // ONLY the handler call is wrapped: a throw here is a HANDLER failure, routed to retry
        // (transient) or the DLQ (permanent). The markProcessed write is deliberately OUTSIDE this try — if it
        // failed it would be an INFRA fault, and catching it here would wrongly record a
        // successfully-handled event as 'failed' (corrupting the audit trail and, since the
        // job is gone from the queue, never reaching 'processed'). An infra failure of the
        // status write instead falls through to the outer backstop, leaving the row in
        // 'processing' — honest, and the stuck-'processing' reaper is future work.
        try {
            $handler->handle($event);
        } catch (TransientHandlerException $e) {
            // T2.3: while attempts remain, schedule a retry with exponential backoff (mark the
            // row back to 'received', ZADD the envelope to webhooks:retry); on exhaustion,
            // retryOrFail hands the job to deadLetter (mark 'failed' + webhooks:dlq, T2.4).
            $scheduler->retryOrFail($events, $row['id'], $job, $e->getMessage());
            continue;
        } catch (PermanentHandlerException $e) {
            // T2.4: a permanent error cannot succeed by retrying — straight to the DLQ, no
            // retry. deadLetter() marks the row terminally 'failed' with last_error AND pushes
            // the envelope to webhooks:dlq (same terminal path as an exhausted transient).
            $scheduler->deadLetter($events, $row['id'], $job, $e->getMessage());
            continue;
        } catch (\Throwable $e) {
            // Any OTHER Throwable from the handler is treated as transient (ADR 0011): an
            // unforeseen bug should be retried, not silently dropped — same retry path as
            // TransientHandlerException (T2.3).
            $scheduler->retryOrFail($events, $row['id'], $job, $e->getMessage());
            continue;
        }

        // handle() returned normally = SUCCESS. A failure of this write is an infra fault
        // (outer backstop), never a handler failure — so a succeeded event is never
        // mis-recorded as 'failed'.
        $events->markProcessed($row['id']);
    } catch (\Throwable $e) {
        // A SIGTERM/SIGINT that interrupts a blocking BLPOP surfaces here as an EINTR-driven
        // RedisException. During a graceful stop that is expected, not a fault: break cleanly
        // rather than logging it as infra noise and sleeping. We discriminate on the flag, not
        // on brittle phpredis error-string matching (ADR 0015, D3).
        if ($shouldStop) {
            break;
        }

        // Infra fault (Redis BLPOP / a status write failing mid-iteration). Log with the
        // front controller's discipline — full detail to the error log, never leaked
        // onward — then back off briefly so a down dependency does not busy-spin.
        error_log((string) $e);
        sleep(INFRA_BACKOFF_SECONDS);
    }
}

// Reached only via a graceful SIGTERM/SIGINT (the loop has no other exit). The in-flight job,
// if any, has already finished; exit 0 so Docker records a clean stop rather than SIGKILLing
// us after the stop-grace period.
fwrite(STDOUT, "worker: shutdown signal received, exiting cleanly\n");
