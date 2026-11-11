<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class SaveWeeklyLessonRuleRequest extends SemesterScheduleManagementRequest
{
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [...parent::rules(), ...self::lessonRules('', $this->isMethod('PATCH')),
            'weekday' => [...$presence, 'integer:strict', 'between:1,5'],
            'week_pattern' => [...$presence, Rule::in(['all', 'numerator', 'denominator'])],
        ];
    }

    public static function lessonRules(string $prefix, bool $partial = false): array
    {
        $presence = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            $prefix.'pair_number' => [...$presence, 'integer:strict', 'between:1,255'],
            $prefix.'pair_label' => ['sometimes', 'nullable', 'string', 'max:32'],
            $prefix.'starts_at_local' => [...$presence, 'string', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/'],
            $prefix.'ends_at_local' => [...$presence, 'string', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/'],
            $prefix.'subject_id' => [...$presence, 'integer:strict', 'min:1'],
            $prefix.'teacher_id' => [...$presence, 'integer:strict', 'min:1'],
            $prefix.'teacher_ids' => ['sometimes', 'array', 'list', 'size:1'],
            $prefix.'teacher_ids.*' => ['required', 'integer:strict', 'min:1'],
            $prefix.'teachers' => ['prohibited'],
            $prefix.'room' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
