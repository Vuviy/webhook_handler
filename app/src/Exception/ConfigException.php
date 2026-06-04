<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Thrown at bootstrap when configuration is missing or invalid.
 *
 * The message names the offending env KEY, never its value, so that a secret
 * is never embedded into an error message, stack trace or log line.
 */
final class ConfigException extends RuntimeException
{
    public static function missing(string $key): self
    {
        return new self(sprintf('Required configuration "%s" is missing or empty.', $key));
    }

    public static function notInteger(string $key): self
    {
        return new self(sprintf('Configuration "%s" must be an integer.', $key));
    }
}
