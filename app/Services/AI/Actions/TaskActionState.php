<?php

namespace App\Services\AI\Actions;

use App\Exceptions\AiAgentException;

/** Pure transition policy. Expiry is computed by MySQL after acquiring writer locks. */
class TaskActionState
{
    public function next(string $status, bool $expired, string $operation): string
    {
        if (! in_array($status, ['pending', 'executed', 'rejected', 'expired'], true)
            || ! in_array($operation, ['confirm', 'reject'], true)) {
            throw new AiAgentException('persistence_error');
        }
        if ($status === 'executed') {
            if ($operation === 'reject') {
                throw new AiAgentException('action_executed');
            }

            return 'executed';
        }
        if ($status !== 'pending') {
            return $status;
        }
        if ($expired) {
            return 'expired';
        }

        return $operation === 'confirm' ? 'executed' : 'rejected';
    }
}
