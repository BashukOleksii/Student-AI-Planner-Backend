<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** Explicit academic-only presentation; no model attributes or self-study sessions escape. */
class AcademicCalendarLessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource, ['id', 'source_identity', 'source_type', 'source', 'occurs_on', 'occurrence_id',
            'lesson_id', 'academic_period_id', 'semester_schedule_id', 'weekly_lesson_rule_id',
            'schedule_date_exception_id', 'schedule_date_exception_lesson_id', 'replaces_lesson_id',
            'subject', 'teacher', 'type', 'pair_number', 'pair_label', 'room', 'starts_at', 'ends_at']);
    }
}
