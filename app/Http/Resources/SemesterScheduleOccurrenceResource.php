<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemesterScheduleOccurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip([
            'occurrence_id', 'occurs_on', 'source', 'pair_number', 'pair_label',
            'subject', 'teacher', 'room', 'starts_at', 'ends_at',
            'weekly_lesson_rule_id', 'schedule_date_exception_id',
        ]));
    }
}
