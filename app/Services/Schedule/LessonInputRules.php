<?php

namespace App\Services\Schedule;

use App\Enums\LessonType;
use Illuminate\Validation\Rule;

class LessonInputRules
{
    public static function rules(bool $creating): array
    {
        $presence = $creating ? ['required'] : ['sometimes', 'required'];
        $timestamp = ['bail', ...$presence, 'string', 'date_format:Y-m-d\TH:i:s\Z',
            'after_or_equal:1000-01-01T00:00:00Z', 'before_or_equal:9999-12-31T23:59:59Z'];

        return [
            'subject_id' => [...$presence, 'integer:strict', 'min:1'],
            'teacher_id' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
            'academic_period_id' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
            'type' => [...$presence, 'string', Rule::enum(LessonType::class)],
            'room' => ['sometimes', 'nullable', 'string', 'max:100'],
            'starts_at' => $timestamp,
            'ends_at' => $timestamp,
        ];
    }
}
