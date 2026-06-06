<?php

declare(strict_types=1);

/**
 * CLI dead-letter-queue drain / re-queue (T3.2).
 *
 * Usage (inside the PHP container):
 *   docker compose exec php php bin/requeue-dlq.php
 *
 * Puts every event currently on webhooks:dlq back on the live path: for each entry it resets the
 * webhook_events row to 'received' (attempts=0, last_error cleared) and re-enqueues a fresh
 * attempt=1 job, so the worker re-processes it. Run this once the downstream cause of the failures
 * has been fixed. Prints one line per consumed entry and a summary; exits 0 on a completed drain,
 * 1 (with a typed exception to STDERR) on an infra fault (Redis/MySQL down).
 *
 * This is an OPERATOR action, not a request — the same category as bin/migrate.php. It boots the
 * autoloader + config the same way, builds Queue + EventRepository via the fail-fast factories
 * (so a down dependency exits loudly at boot), and lives in bin/ above the Nginx web root in
 * public/, so it is NEVER reachable over HTTP. The shell / `docker compose exec` boundary IS the
 * authorization — which is why T3.2 is a CLI and not an unauthenticated dashboard button
 * (ADR 0017, Decision 1).
 *
 * Scope (ADR 0017, Decision 2): this drains ALL entries (the "downstream is healthy, replay the
 * backlog" case). A single-event mode (`requeue-dlq.php <event_id>`) and a `--limit` flag are
 * documented next niceties, deliberately not built here.
 */

use App\Config\Config;
use App\Database\Connection;
use App\Exception\DatabaseException;
use App\Exception\QueueException;
use App\Queue\DlqRequeue;
use App\Queue\DlqRequeueReport;
use App\Queue\Job;
use App\Queue\Queue;
use App\Repository\EventRepository;

/** @var Config $config */
$config = require __DIR__ . '/../bootstrap.php';

try {
    $queue = Queue::fromConfig($config->redis());
    $events = new EventRepository(Connection::fromConfig($config->database()));

    $requeue = new DlqRequeue($queue, $events);

    // Stream one line per consumed entry as the drain runs (the per-unit STDOUT shape of
    // migrate.php). The orchestrator stays I/O-free; only this entry point writes to the console.
    $onEntry = static function (string $outcome, ?Job $job): void {
        $line = match ($outcome) {
            'requeued' => sprintf("re-queued: %s %s\n", $job?->provider(), $job?->eventId()),
            'skipped' => sprintf("skipped:   %s %s (no failed row)\n", $job?->provider(), $job?->eventId()),
            'corrupt' => "skipped:   <corrupt envelope> (could not decode)\n",
            default => null,
        };

        if ($line !== null) {
            fwrite(STDOUT, $line);
        }
    };

    $report = $requeue->drainAll($onEntry);

    if ($report->total() === 0) {
        fwrite(STDOUT, "Dead-letter queue is empty — nothing to re-queue.\n");
        exit(0);
    }

    printSummary($report);
    exit(0);
} catch (QueueException | DatabaseException | RedisException $e) {
    // Infra fault (Redis or MySQL unreachable / a write failed). Typed message to STDERR, never the
    // raw payload or a secret (the exceptions are written not to carry them), and a non-zero exit so
    // an operator or a wrapping script sees the failure — exactly bin/migrate.php's discipline.
    // RedisException is included because — unlike migrate.php, which only touches the DB — this CLI
    // drives raw phpredis reads (lLen/lPop) whose runtime faults propagate as RedisException, not
    // QueueException (only Queue::fromConfig wraps it); without it a mid-drain Redis drop would
    // escape as an uncaught fatal instead of the clean exit(1) above (review T3.2, MAJOR #1).
    fwrite(STDERR, sprintf("Re-queue error: %s\n", $e->getMessage()));
    exit(1);
}

/**
 * Print the end-of-run tally. Always reports re-queued + skipped; mentions corrupt entries only
 * when there were any, so a clean drain stays uncluttered.
 */
function printSummary(DlqRequeueReport $report): void
{
    if ($report->corrupt() > 0) {
        fwrite(STDOUT, sprintf(
            "Done — %d re-queued, %d skipped, %d corrupt (of %d).\n",
            $report->requeued(),
            $report->skipped(),
            $report->corrupt(),
            $report->total(),
        ));

        return;
    }

    fwrite(STDOUT, sprintf(
        "Done — %d re-queued, %d skipped (of %d).\n",
        $report->requeued(),
        $report->skipped(),
        $report->total(),
    ));
}
