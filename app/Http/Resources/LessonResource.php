<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_period_id' => $this->ownedAssociationId('academicPeriod', $request),
            'subject_id' => $this->ownedAssociationId('subject', $request),
            'teacher_id' => $this->ownedAssociationId('teacher', $request),
            'type' => $this->type->value,
            'room' => $this->room,
            'starts_at' => $this->utcTimestamp('starts_at'),
            'ends_at' => $this->utcTimestamp('ends_at'),
            'status' => $this->status->value,
            'replaces_lesson_id' => $this->ownedAssociationId('replacedLesson', $request),
            'replacement_id' => $this->ownedAssociationId('replacement', $request),
        ];
    }

    private function ownedAssociationId(string $relation, Request $request): ?int
    {
        $record = $this->$relation;

        return $record !== null && $record->user_id === $this->user_id
            && $record->user_id === $request->user()?->id ? $record->getKey() : null;
    }

    private function utcTimestamp(string $field): string
    {
        // DATETIME stores UTC wall-clock values; never infer their zone from PHP's default.
        return CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $this->resource->getRawOriginal($field), 'UTC')
            ->format('Y-m-d\TH:i:s\Z');
    }
}
