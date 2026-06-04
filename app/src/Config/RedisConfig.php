<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Immutable Redis connection settings. Consumed by the queue client (T0.4).
 * No connection is opened here.
 */
final readonly class RedisConfig
{
    public function __construct(
        private string $host,
        private int $port,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            host: Env::required('REDIS_HOST'),
            port: Env::requiredInt('REDIS_PORT'),
        );
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }
}
