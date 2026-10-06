<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleLessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subject = $this->ownedRelation('subject', $request);
        $teacher = $this->ownedRelation('teacher', $request);

        return [
            'id' => $this->id,
            'subject' => $subject?->only(['id', 'name', 'code', 'color']),
            'teacher' => $teacher?->only(['id', 'name']),
            'type' => $this->type->value,
            'room' => $this->room,
            'starts_at' => $this->localTimestamp('starts_at', $request),
            'ends_at' => $this->localTimestamp('ends_at', $request),
            'replaces_lesson_id' => $this->ownedRelation('replacedLesson', $request)?->getKey(),
        ];
    }

    private function ownedRelation(string $relation, Request $request): ?Model
    {
        $record = $this->$relation;

        return $record !== null && $record->user_id === $this->user_id
            && $record->user_id === $request->user()?->id ? $record : null;
    }

    private function localTimestamp(string $field, Request $request): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $this->resource->getRawOriginal($field), 'UTC')
            ->setTimezone($request->user()->timezone)->format('Y-m-d\TH:i:sP');
    }
}
