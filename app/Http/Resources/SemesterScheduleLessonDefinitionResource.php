<?php

namespace App\Http\Resources;

use App\Models\WeeklyLessonRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemesterScheduleLessonDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $weekly = $this->resource instanceof WeeklyLessonRule;

        return [
            'id' => $this->id,
            'weekday' => $this->when($weekly, fn () => $this->weekday),
            'week_pattern' => $this->when($weekly, fn () => $this->week_pattern),
            'pair_number' => $this->pair_number, 'pair_label' => $this->pair_label,
            'starts_at_local' => $this->starts_at_local, 'ends_at_local' => $this->ends_at_local,
            'subject' => $this->subject->only(['id', 'name', 'code', 'color']),
            'teacher' => $this->teacher?->only(['id', 'name']), 'room' => $this->room,
            'is_manually_managed' => $this->when($weekly, fn () => $this->is_manually_managed),
            'semester_schedule_import_id' => $this->when($weekly, fn () => $this->semester_schedule_import_id),
            'source_weekly_rule_id' => $this->when(! $weekly, fn () => $this->source_weekly_rule_id),
        ];
    }
}
