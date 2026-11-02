<?php

namespace App\Services\AI\Actions;

use App\Models\Lesson;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class LessonActionPresenter
{
    public function lesson(User $owner, Lesson $lesson): array
    {
        $values = $lesson->only(['subject_id', 'teacher_id', 'academic_period_id', 'type', 'room', 'status']);
        foreach (['starts_at', 'ends_at'] as $field) {
            $values[$field] = CarbonImmutable::parse($lesson->getRawOriginal($field), 'UTC');
        }

        return ['id' => $lesson->id, ...$this->values($owner, $values),
            'replaces_lesson_id' => $owner->lessons()->whereKey($lesson->replaces_lesson_id)->value('id'),
            'replacement_id' => $lesson->replacement()->where('user_id', $owner->id)->value('id')];
    }

    public function values(User $owner, array $values): array
    {
        foreach ($values as $field => $value) {
            $values[$field] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z'),
                default => $value,
            };
        }
        foreach (['subject' => 'subjects', 'teacher' => 'teachers', 'academic_period' => 'academicPeriods'] as $label => $relation) {
            $record = isset($values[$label.'_id']) ? $owner->$relation()->find($values[$label.'_id']) : null;
            $values[$label.'_id'] = $record?->id;
            $values[$label] = $record === null ? null : ['id' => $record->id, 'name' => $record->name];
        }

        return $values;
    }
}
