<?php

namespace App\Exceptions;

use RuntimeException;

/** Only fixed public errors; never constructed from upstream messages. */
class AiAgentException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct(match ($errorCode) {
            'ai_unavailable' => 'The AI service is unavailable.',
            'provider_error' => 'The AI provider could not complete the request.',
            'invalid_provider_response' => 'The AI provider returned an invalid response.',
            'conversation_busy' => 'This conversation already has an active turn.',
            'turn_expired' => 'This conversation turn has expired. Retry with the same idempotency key.',
            'idempotency_conflict' => 'This idempotency key was already used for different content.',
            'execution_limit_reached' => 'The AI request exceeded an execution limit. Narrow the request or start a new conversation.',
            default => 'The AI conversation could not be processed.',
        });
    }

    public function status(): int
    {
        return match ($this->errorCode) {
            'ai_unavailable' => 503,
            'provider_error', 'invalid_provider_response' => 502,
            'conversation_busy', 'turn_expired', 'idempotency_conflict' => 409,
            'execution_limit_reached' => 422,
            default => 500,
        };
    }
}
