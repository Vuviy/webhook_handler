<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Immutable database connection settings. Consumed by the PDO connection (T0.3).
 * No connection is opened here — this only carries validated values.
 */
final readonly class DatabaseConfig
{
    public function __construct(
        private string $host,
        private string $name,
        private string $username,
        private string $password,
        private string $driver,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            host: Env::required('DB_HOST'),
            name: Env::required('DB_DATABASE'),
            username: Env::required('DB_USERNAME'),
            password: Env::required('DB_PASSWORD'),
            driver: Env::get('DB_DRIVER', 'mysql') ?? 'mysql',
        );
    }

    public function host(): string
    {
        return $this->host;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function username(): string
    {
        return $this->username;
    }

    /** Secret — do not log. */
    public function password(): string
    {
        return $this->password;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /** Convenience DSN for PDO (built in T0.3). */
    public function dsn(): string
    {
        return sprintf('%s:host=%s;dbname=%s;charset=utf8mb4', $this->driver, $this->host, $this->name);
    }
}
