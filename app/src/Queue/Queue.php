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
 * Scope (T0.4): connect + enqueue + read the queue length. T2.2 adds the consume
 * side (BLPOP → typed Job). The delayed-retry sorted set (T2.3) and the dead-letter
 * list (T2.4) belong to the later worker tasks and are deliberately not here yet.
 *
 * The heavy webhook payload stays in MySQL (webhook_events); a job is only a
 * small reference by event id, so the worker re-reads the source of record.
 */
final class Queue
{
    /** Main work queue: RPUSH to add (enqueue), BLPOP to consume (consume). */
    private const QUEUE = 'webhooks:queue';

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
