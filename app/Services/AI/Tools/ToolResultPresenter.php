<?php

namespace App\Services\AI\Tools;

use App\Models\Lesson;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** HTTP-independent equivalents of the approved Task/ScheduleLesson Resource fields. */
class ToolResultPresenter
{
    public function task(User $owner, Task $task): array
    {
        return [
            'id' => $task->id,
            'subject_id' => $this->ownedRelation($owner, $task, 'subject')?->getKey(),
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'estimated_minutes' => $task->estimated_minutes,
            'deadline_at' => $this->utcTimestamp($task, 'deadline_at'),
            'completed_at' => $this->utcTimestamp($task, 'completed_at'),
        ];
    }

    public function lesson(User $owner, Lesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'subject' => $this->ownedRelation($owner, $lesson, 'subject')?->only(['id', 'name', 'code', 'color']),
            'teacher' => $this->ownedRelation($owner, $lesson, 'teacher')?->only(['id', 'name']),
            'type' => $lesson->type->value,
            'room' => $lesson->room,
            'starts_at' => $this->localTimestamp($owner, $lesson, 'starts_at'),
            'ends_at' => $this->localTimestamp($owner, $lesson, 'ends_at'),
            'replaces_lesson_id' => $this->ownedRelation($owner, $lesson, 'replacedLesson')?->getKey(),
        ];
    }

    private function ownedRelation(User $owner, Model $record, string $relation): ?Model
    {
        $related = $record->$relation;

        return $record->user_id === $owner->id && $related?->user_id === $owner->id ? $related : null;
    }

    private function utcTimestamp(Model $record, string $field): ?string
    {
        $value = $record->getRawOriginal($field);

        return $value === null ? null : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC')->format('Y-m-d\TH:i:s\Z');
    }

    private function localTimestamp(User $owner, Lesson $lesson, string $field): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $lesson->getRawOriginal($field), 'UTC')
            ->setTimezone($owner->timezone)->format('Y-m-d\TH:i:sP');
    }
}
