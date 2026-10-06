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
            $candidate = array_replace($this->persistedCandidate($lesson), $this->manualValues($values));
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
            $lesson = $user->lessons()->lockForUpdate()->findOrFail($lesson->getKey());
            $child = $lesson->replacement()->withTrashed()->lockForUpdate()->first();
            if ($child !== null && (! $child->trashed() || $child->user_id !== $user->id)) {
                $this->invalidReplacement();
            }
            if ($lesson->replaces_lesson_id !== null) {
                $original = $this->replacementOriginal($user, $lesson);
                $this->validateCandidate($user, $this->persistedCandidate($original), LessonStatus::Active, $original, [$lesson->id]);
                $lesson->delete();
                $original->update(['status' => LessonStatus::Active]);
            } else {
                $lesson->delete();
            }
        });
    }

    public function cancel(User $user, Lesson $lesson): Lesson
    {
        return DB::transaction(function () use ($user, $lesson): Lesson {
            $this->lockOwner($user);
            $lesson = $user->lessons()->lockForUpdate()->findOrFail($lesson->getKey());
            if ($lesson->status !== LessonStatus::Active) {
                throw ValidationException::withMessages(['status' => ['Only an active lesson can be cancelled.']]);
            }
            if ($lesson->replaces_lesson_id !== null) {
                $this->replacementOriginal($user, $lesson);
            } elseif ($lesson->replacement()->withTrashed()->whereNull('deleted_at')->lockForUpdate()->first() !== null) {
                $this->invalidReplacement();
            }
            $lesson->update(['status' => LessonStatus::Cancelled]);

            return $lesson->refresh();
        });
    }

    public function createReplacement(User $user, Lesson $original, array $values): Lesson
    {
        return DB::transaction(function () use ($user, $original, $values): Lesson {
            $this->lockOwner($user);
            $original = $user->lessons()->lockForUpdate()->findOrFail($original->getKey());
            if ($original->status !== LessonStatus::Active || $original->replaces_lesson_id !== null) {
                $this->invalidReplacement();
            }
            // Search physical history: a deleted row still reserves the unique original ID.
            $replacement = $original->replacement()->withTrashed()->lockForUpdate()->first();
            if ($replacement !== null && ($replacement->id === $original->id
                || $replacement->user_id !== $user->id || ! $replacement->trashed()
                || ! in_array($replacement->status, [LessonStatus::Active, LessonStatus::Cancelled], true)
                || $replacement->schedule_import_batch_id !== null || $replacement->import_fingerprint !== null
                || $replacement->replacement()->withTrashed()->lockForUpdate()->first() !== null)) {
                $this->invalidReplacement();
            }
            $candidate = ['teacher_id' => null, 'academic_period_id' => null, 'room' => null,
                ...$this->manualValues($values)];
            $this->validateCandidate($user, $candidate, LessonStatus::Active, null, [$original->id]);
            if ($replacement === null) {
                $replacement = $user->lessons()->create([
                    ...$candidate, 'replaces_lesson_id' => $original->id, 'status' => LessonStatus::Active,
                ]);
            } else {
                $replacement->fill([...$candidate, 'status' => LessonStatus::Active]);
                $replacement->restore();
            }
            $original->update(['status' => LessonStatus::Replaced]);

            return $replacement->refresh();
        });
    }

    private function replacementOriginal(User $user, Lesson $replacement): Lesson
    {
        if ($replacement->replaces_lesson_id === $replacement->id
            || ! in_array($replacement->status, [LessonStatus::Active, LessonStatus::Cancelled], true)
            || $replacement->replacement()->withTrashed()->lockForUpdate()->first() !== null) {
            $this->invalidReplacement();
        }
        $original = $user->lessons()->withTrashed()->whereKey($replacement->replaces_lesson_id)->lockForUpdate()->first();
        if ($original === null || $original->trashed() || $original->status !== LessonStatus::Replaced
            || $original->replaces_lesson_id !== null) {
            $this->invalidReplacement();
        }

        return $original;
    }

    private function invalidReplacement(): never
    {
        throw ValidationException::withMessages(['replacement' => ['The lesson replacement operation is not available.']]);
    }

    private function persistedCandidate(Lesson $lesson): array
    {
        return [...$lesson->only(['subject_id', 'teacher_id', 'academic_period_id', 'type', 'room']),
            'starts_at' => CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $lesson->getRawOriginal('starts_at'), 'UTC'),
            'ends_at' => CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $lesson->getRawOriginal('ends_at'), 'UTC')];
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

    private function validateCandidate(User $user, array $candidate, LessonStatus $status, ?Lesson $lesson = null, array $excludedIds = []): void
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
                ->whereNotIn('id', $excludedIds)
                ->lockForUpdate()->first();
            if ($overlap !== null) {
                throw ValidationException::withMessages(['schedule' => ['This lesson overlaps an existing active lesson.']]);
            }
        }
    }
}
