<?php

namespace App\Services\AI\Actions;

use App\Exceptions\AiAgentException;
use App\Models\AiPendingAction;
use Throwable;

/** Read the reviewed consent only; never refresh it or downgrade corrupt protected data. */
class WorkCompletionConsent
{
    public static function token(AiPendingAction $action): string
    {
        try {
            if ($action->getRawOriginal('protected_payload') !== null) {
                $token = $action->protected_payload['completion_token'] ?? null;
            } else {
                // Compatibility for persisted proposals created before consent was protected.
                $token = $action->preview['completion_review']['completion_token'] ?? null;
            }
        } catch (Throwable) {
            throw new AiAgentException('action_validation_failed');
        }
        if (! is_string($token) || $token === '') {
            throw new AiAgentException('action_validation_failed');
        }

        return $token;
    }
}
