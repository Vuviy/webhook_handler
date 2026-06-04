<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Aggregate application configuration, built once at bootstrap from the
 * environment and passed down to the components that need it.
 *
 * This object opens no connections; it only carries validated, typed values.
 * Required DB/Redis settings fail fast in the sub-objects' fromEnv() factories.
 */
final readonly class Config
{
    public function __construct(
        private DatabaseConfig $database,
        private RedisConfig $redis,
        private ProviderSecrets $providers,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            database: DatabaseConfig::fromEnv(),
            redis: RedisConfig::fromEnv(),
            providers: ProviderSecrets::fromEnv(),
        );
    }

    public function database(): DatabaseConfig
    {
        return $this->database;
    }

    public function redis(): RedisConfig
    {
        return $this->redis;
    }

    public function providers(): ProviderSecrets
    {
        return $this->providers;
    }
}
