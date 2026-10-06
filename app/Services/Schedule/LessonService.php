<?php

namespace App\Services\Schedule;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LessonService
{
    private const MANUAL_FIELDS = ['subject_id', 'teacher_id', 'academic_period_id', 'type', 'room', 'starts_at', 'ends_at'];

    public function create(User $user, array $values): Lesson
    {
        return DB::transaction(function () use ($user, $values): Lesson {
            $this->lockOwner($user);
            $candidate = ['teacher_id' => null, 'academic_period_id' => null, 'room' => null,
                ...$this->manualValues($values)];
            $this->validateCandidate($user, $candidate, LessonStatus::Active);

            return $user->lessons()->create($candidate)->refresh();
        });
    }

    public function update(User $user, Lesson $lesson, array $values): Lesson
    {
        return DB::transaction(function () use ($user, $lesson, $values): Lesson {
            $this->lockOwner($user);
            $lesson = $user->lessons()->lockForUpdate()->findOrFail($lesson->getKey());
            $candidate = array_replace($lesson->only(['subject_id', 'teacher_id', 'academic_period_id', 'type', 'room']), [
                'starts_at' => CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $lesson->getRawOriginal('starts_at'), 'UTC'),
                'ends_at' => CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $lesson->getRawOriginal('ends_at'), 'UTC'),
            ], $this->manualValues($values));
            $this->validateCandidate($user, $candidate, $lesson->status, $lesson);
            try {
                $lesson->update($candidate);
            } catch (UniqueConstraintViolationException) {
                // Only changing the period can collide with immutable persisted fingerprint history.
                throw ValidationException::withMessages(['academic_period_id' => ['The selected academic period conflicts with existing lesson history.']]);
            }

            return $lesson->refresh();
        });
    }

    public function delete(User $user, Lesson $lesson): void
    {
        DB::transaction(function () use ($user, $lesson): void {
            $this->lockOwner($user);
            $user->lessons()->lockForUpdate()->findOrFail($lesson->getKey())->delete();
        });
    }

    private function lockOwner(User $user): void
    {
        User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function manualValues(array $values): array
    {
        $values = Arr::only($values, self::MANUAL_FIELDS);
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $values[$field], 'UTC');
            }
        }

        return $values;
    }

    private function validateCandidate(User $user, array $candidate, LessonStatus $status, ?Lesson $lesson = null): void
    {
        foreach (['subject_id' => 'subjects', 'teacher_id' => 'teachers', 'academic_period_id' => 'academicPeriods'] as $field => $relation) {
            if ($candidate[$field] === null && $field !== 'subject_id') {
                continue;
            }
            if ($user->$relation()->whereKey($candidate[$field])->lockForUpdate()->first() === null) {
                throw ValidationException::withMessages([$field => ['The selected association is invalid.']]);
            }
        }

        if ($lesson?->import_fingerprint !== null && $candidate['academic_period_id'] === null) {
            throw ValidationException::withMessages(['academic_period_id' => ['This lesson requires an academic period.']]);
        }

        if ($candidate['starts_at']->greaterThanOrEqualTo($candidate['ends_at'])) {
            throw ValidationException::withMessages(['ends_at' => ['The end time must be after the start time.']]);
        }

        if ($status === LessonStatus::Active) {
            $overlap = $user->lessons()->where('status', LessonStatus::Active)
                ->where('starts_at', '<', $candidate['ends_at'])->where('ends_at', '>', $candidate['starts_at'])
                ->when($lesson !== null, fn ($query) => $query->whereKeyNot($lesson->getKey()))
                ->lockForUpdate()->first();
            if ($overlap !== null) {
                throw ValidationException::withMessages(['schedule' => ['This lesson overlaps an existing active lesson.']]);
            }
        }
    }
}
