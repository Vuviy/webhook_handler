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
 * Scope (T0.4): connect + enqueue + read the queue length. Consuming (BLPOP),
 * the delayed-retry sorted set and the dead-letter list belong to the worker
 * tasks (T2.x) and are deliberately not implemented yet.
 *
 * The heavy webhook payload stays in MySQL (webhook_events); a job is only a
 * small reference by event id, so the worker re-reads the source of record.
 */
final class Queue
{
    /** Main work queue: RPUSH to add, BLPOP to consume (consume lands in T2.2). */
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
}
