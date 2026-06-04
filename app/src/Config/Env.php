<?php

declare(strict_types=1);

namespace App\Config;

use App\Exception\ConfigException;

/**
 * Thin, side-effect-free reader over the process / dotenv environment.
 *
 * phpdotenv (immutable mode) populates $_ENV and $_SERVER for keys that are not
 * already present in the real process environment. We therefore read from those
 * superglobals (process-injected values win, dotenv fills the gaps) rather than
 * getenv(), which the default phpdotenv adapters do not write to.
 */
final class Env
{
    /** Optional value: returns the default when absent or empty. */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    /** Required string: throws when missing or empty (fail fast at bootstrap). */
    public static function required(string $key): string
    {
        $value = self::get($key);

        if ($value === null) {
            throw ConfigException::missing($key);
        }

        return $value;
    }

    /** Required integer: throws when missing or non-numeric. */
    public static function requiredInt(string $key): int
    {
        $value = self::required($key);

        if (!ctype_digit($value)) {
            throw ConfigException::notInteger($key);
        }

        return (int) $value;
    }
}
