<?php

namespace App\Services\AI\Actions;

use Illuminate\Validation\ValidationException;

/** Client-only fields: never part of provider definitions or stored action arguments. */
class PlanningActionConsent
{
    public static function fields(string $type): array
    {
        return match ($type) {
            'propose_study_plan_create' => ['accept_partial'],
            'propose_adaptive_replanning' => ['accept_partial', 'accept_unplaced_cancellation'],
            default => [],
        };
    }

    public static function validate(string $type, string $operation, array $values): void
    {
        $allowed = $operation === 'confirm' ? self::fields($type) : [];
        foreach ($values as $field => $value) {
            if (! in_array($field, $allowed, true) || ! is_bool($value)) {
                throw ValidationException::withMessages([$field => ['Only approved genuine boolean consent fields are accepted.']]);
            }
        }
    }
}
