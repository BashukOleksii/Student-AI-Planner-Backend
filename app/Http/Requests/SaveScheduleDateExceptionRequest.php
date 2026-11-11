<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class SaveScheduleDateExceptionRequest extends SemesterScheduleManagementRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'mode' => ['required', Rule::in(['replace', 'copy_base'])],
            'lessons' => ['present_if:mode,replace', 'array', 'list', 'max:255'],
            'lessons.*' => ['required', 'array:id,pair_number,pair_label,starts_at_local,ends_at_local,subject_id,teacher_id,teacher_ids,room,lesson_type'],
            'lessons.*.id' => ['sometimes', 'integer:strict', 'min:1', 'distinct:strict'],
            ...SaveWeeklyLessonRuleRequest::lessonRules('lessons.*.'),
            'changes' => ['sometimes', 'prohibited_unless:mode,copy_base', 'array', 'list', 'max:255'],
            'changes.*' => ['required', 'array:weekly_lesson_rule_id,cancelled,pair_number,pair_label,starts_at_local,ends_at_local,subject_id,teacher_id,teacher_ids,room,lesson_type'],
            'changes.*.weekly_lesson_rule_id' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
            'changes.*.cancelled' => ['sometimes', 'boolean:strict'],
            ...SaveWeeklyLessonRuleRequest::lessonRules('changes.*.', true),
        ];
    }
}
