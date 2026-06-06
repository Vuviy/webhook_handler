<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A shared, ordered journal of the calls made on the test doubles.
 *
 * The retry tests must assert not only THAT a collaborator was called with the right arguments but
 * the ORDER across two different doubles — RetryScheduler writes the DB row (markForRetry/markFailed
 * on the EventWriter) BEFORE it touches Redis (scheduleRetry/deadLetter on the RetryQueue), the
 * "DB write first" crash-safety rule. A single CallLog handed to both fakes records every call into
 * one timeline, so a test can read back the exact sequence with methods().
 */
final class CallLog
{
    /** @var list<array{method: string, args: array<int, mixed>}> */
    private array $calls = [];

    /**
     * @param array<int, mixed> $args
     */
    public function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }

    /**
     * The method names in call order, e.g. ['markForRetry', 'scheduleRetry'].
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return array_map(static fn (array $call): string => $call['method'], $this->calls);
    }

    /**
     * The recorded arguments for the single call to $method.
     *
     * @return array<int, mixed>
     */
    public function argsFor(string $method): array
    {
        foreach ($this->calls as $call) {
            if ($call['method'] === $method) {
                return $call['args'];
            }
        }

        return [];
    }
}
