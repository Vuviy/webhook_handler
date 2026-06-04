<?php

declare(strict_types=1);

namespace App;

/**
 * Tiny bootstrap-stage class.
 *
 * Its only job right now is to prove that PSR-4 autoloading (App\ -> src/)
 * is wired correctly end to end: the front controller can instantiate this
 * class without any manual `require`. Real components (router, config, DB,
 * queue) arrive in later subtasks.
 */
final class Health
{
    /**
     * @return array{status: string, service: string}
     */
    public function status(): array
    {
        return [
            'status' => 'ok',
            'service' => 'webhook-handler',
        ];
    }
}
