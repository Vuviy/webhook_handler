<?php

declare(strict_types=1);

namespace App\Queue;

use App\Config\RedisConfig;
use App\Exception\QueueException;
use JsonException;
use Redis;
use RedisException;

/**
 * The webhook job queue, backed by a Redis list.
 *
 * Unlike App\Database\Connection (a thin PDO factory), this is a deliberate
 * domain abstraction, not a passthrough: it owns the queue key name and the JSON
 * job-envelope schema, so no caller ever hardcodes "webhooks:queue" or re-invents
 * the envelope. That added vocabulary is why wrapping is justified here even
 * though single-implementation passthrough wrappers are discouraged.
 *
 * Scope (T0.4): connect + enqueue + read the queue length. T2.2 added the consume
 * side (BLPOP → typed Job). T2.3 added the delayed-retry sorted set mechanism
 * (scheduleRetry + promoteDueRetries). T2.4 adds the dead-letter list mechanism
 * (deadLetter) for jobs that exhausted their retries or failed permanently; draining /
 * re-queuing the DLQ is T3.2 and is deliberately not here yet.
 *
 * The heavy webhook payload stays in MySQL (webhook_events); a job is only a
 * small reference by event id, so the worker re-reads the source of record.
 */
final class Queue
{
    /** Main work queue: RPUSH to add (enqueue), BLPOP to consume (consume). */
    private const QUEUE = 'webhooks:queue';

    /**
     * Delayed-retry sorted set (T2.3): ZADD a job with score = ready-at unix time,
     * promoteDueRetries() moves it back to QUEUE once that time has passed. The score is
     * the backoff deadline; the BACKOFF POLICY that computes it lives in RetryScheduler.
     */
    private const RETRY = 'webhooks:retry';

    /**
     * Dead-letter list (T2.4): RPUSH a job here once it has exhausted its 3 attempts or
     * failed permanently. Unlike RETRY, nothing in the worker ever moves a job back out of
     * here automatically — the DLQ is terminal storage for human/operator attention, and the
     * drain / re-queue path is T3.2. The DECISION to dead-letter (exhaustion vs permanent)
     * lives in RetryScheduler; Queue only owns the key name and the RPUSH mechanism.
     */
    private const DLQ = 'webhooks:dlq';

    /** Fail fast instead of hanging PHP-FPM if Redis is unreachable. */
    private const CONNECT_TIMEOUT_SECONDS = 1.5;

    public function __construct(
        private readonly Redis $redis,
    ) {
    }

    public static function fromConfig(RedisConfig $config): self
    {
        $redis = new Redis();

        try {
            // @-silence only phpredis's native connect() Warning: it duplicates the
            // failure we are about to convert into a typed QueueException below. It
            // does NOT swallow the RedisException throw, and the === false check stands.
            $connected = @$redis->connect($config->host(), $config->port(), self::CONNECT_TIMEOUT_SECONDS);
        } catch (RedisException $e) {
            throw QueueException::connectionFailed($config->host(), $config->port(), $e);
        }

        if ($connected === false) {
            throw QueueException::connectionFailed($config->host(), $config->port());
        }

        return new self($redis);
    }

    /**
     * Append a job to the back of the queue (RPUSH); the worker consumes from the
     * front (BLPOP), giving FIFO order. Idempotency is the consumer's job: delivery
     * is at-least-once, so dedupe happens by the (provider, event_id) unique key.
     */
    public function enqueue(string $eventId, string $provider, int $attempt = 1): void
    {
        // A queue that silently drops a job breaks at-least-once delivery: the
        // caller believes the event is queued when it is not. rPush returns the new
        // list length, or false on failure — treat false as a hard, loud error.
        $length = $this->redis->rPush(self::QUEUE, $this->encodeJob($eventId, $provider, $attempt));

        if ($length === false) {
            throw QueueException::enqueueFailed($eventId);
        }
    }

    /**
     * Block until a job is available, then pop it off the FRONT of the queue (BLPOP),
     * giving FIFO order against enqueue()'s RPUSH at the back. Returns the decoded,
     * typed Job, or null when no job arrived within $timeoutSeconds.
     *
     * Why a finite timeout instead of blocking forever (BLPOP … 0): the worker loop
     * regains control every $timeoutSeconds even when idle, which is the natural place
     * for graceful shutdown (T2.5) to check its stop flag and for the loop to notice a
     * dropped connection — at the cost of one cheap empty wakeup per interval. A timeout
     * of 0 would block indefinitely and surface neither (ADR 0012, Decision 1).
     *
     * BLPOP returns [listName, value] on a hit, or an empty array on timeout. A genuine
     * Redis fault throws RedisException, which we let propagate: the worker's loop-level
     * backstop logs it and backs off (ADR 0012, Decision 4) — a queue must never silently
     * swallow a connection drop, exactly as enqueue() never silently drops a job.
     */
    public function consume(int $timeoutSeconds): ?Job
    {
        $result = $this->redis->blPop([self::QUEUE], $timeoutSeconds);

        // Empty array (or false on some phpredis versions) means the timeout elapsed with
        // no job — a normal idle tick, not an error. Anything else is [listName, rawJob].
        if (!is_array($result) || $result === []) {
            return null;
        }

        return $this->decodeJob($result[1]);
    }

    /** Current number of jobs waiting in the main queue. */
    public function size(): int
    {
        return (int) $this->redis->lLen(self::QUEUE);
    }

    /**
     * Schedule a job for a delayed retry: ZADD the re-encoded envelope into the
     * webhooks:retry sorted set, scored by $readyAt (a unix timestamp). promoteDueRetries()
     * moves it back onto the main queue once that time passes.
     *
     * This is the retry-side mirror of enqueue(): the envelope schema stays sealed inside
     * Queue (reuses encodeJob()) and the key name lives here, not in the caller. The BACKOFF
     * POLICY — how far ahead $readyAt is, and whether to retry at all — is decided by
     * RetryScheduler, not here (ADR 0013, Decision 4): Queue owns the MECHANISM (the zset
     * write), RetryScheduler owns the POLICY (the math, jitter, attempt limit).
     *
     * zAdd() returns the count of NEW members added — 0 when an existing member's score is
     * merely updated (a legitimately re-scheduled same event), or false on a genuine failure.
     * Only false is an error: a dropped retry breaks at-least-once just as a dropped enqueue
     * would, so we raise loudly rather than silently lose the job.
     */
    public function scheduleRetry(string $eventId, string $provider, int $attempt, int $readyAt): void
    {
        $added = $this->redis->zAdd(self::RETRY, $readyAt, $this->encodeJob($eventId, $provider, $attempt));

        if ($added === false) {
            throw QueueException::scheduleRetryFailed($eventId);
        }
    }

    /**
     * Move every retry whose ready-at score has passed (score <= $now) from webhooks:retry
     * back onto the main webhooks:queue, so the worker re-pops it. Returns how many were moved.
     *
     * Non-atomic by design (ADR 0013, Decision 2): ZRANGEBYSCORE reads the due members, then
     * each is RPUSH'd and ZREM'd. With a SINGLE worker there is no concurrent sweeper, so the
     * read-then-remove window cannot double-promote; and even if it ever did (a second worker),
     * the guarded markProcessing() claim makes a duplicate harmless (the second claim matches 0
     * rows and is skipped). A multi-worker deployment should harden this to an atomic Lua
     * ZPOPMIN-style pop — a localized change, not a redesign.
     *
     * RPUSH-then-ZREM order is deliberate: if the process dies between the two, the job stays
     * in the zset and is promoted again next sweep (at-least-once) rather than being lost. We
     * never ZREM a job we failed to RPUSH, for the same reason.
     */
    public function promoteDueRetries(int $now): int
    {
        $due = $this->redis->zRangeByScore(self::RETRY, '-inf', (string) $now);

        if (!is_array($due) || $due === []) {
            return 0;
        }

        $promoted = 0;

        foreach ($due as $job) {
            if ($this->redis->rPush(self::QUEUE, $job) === false) {
                // Leave it in the zset; the next sweep retries the promotion. Never ZREM a job
                // we failed to move, or it would be lost (breaks at-least-once).
                continue;
            }

            $this->redis->zRem(self::RETRY, $job);
            $promoted++;
        }

        return $promoted;
    }

    /**
     * Move a job to the dead-letter list (T2.4): RPUSH the re-encoded envelope onto
     * webhooks:dlq. Called once a job has exhausted its retries or failed permanently — the
     * retry-or-DLQ DECISION belongs to RetryScheduler (which also marks the DB row 'failed'
     * with last_error first); Queue only owns the key name and the write, exactly as it does
     * for enqueue() and scheduleRetry().
     *
     * A plain RPUSH with no read-modify-write: the single worker is the only writer, and the
     * DLQ is terminal (nothing pops it automatically — draining is T3.2), so there is no race
     * to guard. The envelope stays the SAME tiny {event_id, provider, attempt} reference; the
     * human-readable error and timestamps already live on the webhook_events row, so the T3.2
     * drain re-reads the row rather than carrying a fat payload through Redis. $attempt is the
     * last attempt that ran (MAX_ATTEMPTS on exhaustion, the throwing attempt on a permanent
     * error), so the DLQ entry stays coherent with the DB attempts column.
     *
     * rPush returns the new list length, or false on failure — and a job that silently fails
     * to reach the DLQ is a job lost from the audit/recovery path, so false is a hard, loud
     * error, mirroring enqueue() and scheduleRetry().
     */
    public function deadLetter(string $eventId, string $provider, int $attempt): void
    {
        $length = $this->redis->rPush(self::DLQ, $this->encodeJob($eventId, $provider, $attempt));

        if ($length === false) {
            throw QueueException::deadLetterFailed($eventId);
        }
    }

    /**
     * The single definition of the job-envelope schema. Kept tiny on purpose: the
     * real payload lives in the DB, referenced by event id.
     */
    private function encodeJob(string $eventId, string $provider, int $attempt): string
    {
        try {
            return json_encode(
                ['event_id' => $eventId, 'provider' => $provider, 'attempt' => $attempt],
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw QueueException::encodeFailed($eventId, $e);
        }
    }

    /**
     * The mirror of encodeJob(): the ONE place the envelope is read back. Keeping decode
     * here (where encode lives) means the wire schema stays sealed inside Queue and a
     * malformed/legacy envelope is rejected in a single spot rather than re-validated by
     * every caller.
     *
     * A job that cannot be decoded — bad JSON, or missing/wrong-typed fields — is a
     * corrupt queue entry, not a processable event. We raise QueueException rather than
     * return a half-built Job: the worker's loop backstop logs it and moves on, so one
     * poison envelope cannot masquerade as a real event. The factory names neither the
     * raw bytes nor any secret (NFR-1).
     */
    private function decodeJob(string $raw): Job
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw QueueException::decodeFailed($e);
        }

        if (
            !is_array($decoded)
            || !is_string($decoded['event_id'] ?? null)
            || !is_string($decoded['provider'] ?? null)
            || !is_int($decoded['attempt'] ?? null)
        ) {
            throw QueueException::decodeFailed();
        }

        return new Job($decoded['event_id'], $decoded['provider'], $decoded['attempt']);
    }
}
