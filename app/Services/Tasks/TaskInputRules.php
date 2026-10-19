<?php

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use Closure;
use Illuminate\Validation\Rule;

/** Shared REST/AI wire validation. Ownership and candidate constraints stay in TaskService. */
class TaskInputRules
{
    public static function rules(bool $create, array $values = []): array
    {
        $presence = $create ? ['required'] : ['sometimes', 'required'];

        return [
            'title' => [...$presence, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                // MySQL TEXT capacity is measured in bytes, including UTF-8 text.
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('The description must not exceed 65535 bytes.');
                }
            }],
            'subject_id' => ['sometimes', 'nullable', Rule::when(($values['subject_id'] ?? null) !== null, ['required']), 'integer:strict', 'min:1'],
            'priority' => ['sometimes', 'required', 'integer:strict', Rule::enum(TaskPriority::class)],
            'estimated_minutes' => ['sometimes', 'nullable', Rule::when(($values['estimated_minutes'] ?? null) !== null, ['required']), 'integer:strict', 'between:1,65535'],
            'deadline_at' => ['bail', 'sometimes', 'nullable', Rule::when(($values['deadline_at'] ?? null) !== null, ['required']), 'string', 'date_format:Y-m-d\TH:i:s\Z',
                'after_or_equal:1000-01-01T00:00:00Z', 'before_or_equal:9999-12-31T23:59:59Z'],
        ];
    }
}
