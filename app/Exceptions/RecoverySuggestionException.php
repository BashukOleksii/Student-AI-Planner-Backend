<?php

namespace App\Exceptions;

use RuntimeException;

/** Fixed public diagnostics, never source contents, SQL or encrypted tokens. */
class RecoverySuggestionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct(match ($errorCode) {
            'analysis_limit_exceeded' => 'Automatic recovery analysis exceeds its safety limits. Use a narrower manual planning workflow.',
            'suggestion_expired' => 'This recovery review expired. Request a fresh review.',
            'suggestion_stale' => 'The recovery sources changed. Request a fresh review.',
            'suggestion_resolved' => 'This recovery review has already been resolved.',
            'decision_required' => 'This review requires a student lifecycle decision or corrected planning inputs before it can be applied.',
            'no_recovery_needed' => 'No current recovery suggestion is needed.',
            default => 'Recovery suggestions could not be processed.',
        });
    }

    public function status(): int
    {
        return match ($this->errorCode) {
            'suggestion_expired' => 410,
            'suggestion_stale', 'suggestion_resolved', 'no_recovery_needed' => 409,
            'analysis_limit_exceeded', 'decision_required' => 422,
            default => 500,
        };
    }
}
