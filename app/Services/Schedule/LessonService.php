<?php

namespace App\Services\Schedule;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Planning\CalendarBusyIntervalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LessonService
{
    private const MANUAL_FIELDS = ['subject_id', 'teacher_id', 'academic_period_id', 'type', 'room', 'starts_at', 'ends_at'];

    public function __construct(private CalendarBusyIntervalService $calendar) {}

    public function create(User $user, array $values): Lesson
    {
        return DB::transaction(function () use ($user, $values): Lesson {
            $this->lockOwner($user);
            $candidate = $this->prepare($user, $values, lock: true);

            return $user->lessons()->create($candidate)->refresh();
        });
    }

    public function update(User $user, Lesson $lesson, array $values): Lesson
    {
        return DB::transaction(function () use ($user, $lesson, $values): Lesson {
            $this->lockOwner($user);
            $lesson = $user->lessons()->lockForUpdate()->findOrFail($lesson->getKey());
            $candidate = $this->prepare($user, $values, $lesson, true);
            try {
                $lesson->update($candidate);
            } catch (UniqueConstraintViolationException) {
                // Only changing the period can collide with immutable persisted fingerprint history.
                throw ValidationException::withMessages(['academic_period_id' => ['Вибраний навчальний період конфліктує з історією занять.']]);
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
            $this->prepareCancel($user, $lesson, true);
            $lesson->update(['status' => LessonStatus::Cancelled]);

            return $lesson->refresh();
        });
    }

    public function createReplacement(User $user, Lesson $original, array $values): Lesson
    {
        $this->invalidReplacement();
    }

    /** Shared deterministic preflight. It never invokes a writer or saves a speculative model. */
    public function prepare(User $user, array $values, ?Lesson $lesson = null, bool $lock = false): array
    {
        $candidate = array_replace($lesson === null
            ? ['teacher_id' => null, 'academic_period_id' => null, 'room' => null]
            : $this->persistedCandidate($lesson), $this->manualValues($values));
        $this->validateCandidate($user, $candidate, $lesson?->status ?? LessonStatus::Active, $lesson, lock: $lock);

        return $candidate;
    }

    public function prepareCancel(User $user, Lesson $lesson, bool $lock = false): void
    {
        if ($lesson->status !== LessonStatus::Active) {
            throw ValidationException::withMessages(['status' => ['Скасувати можна лише активне заняття.']]);
        }
        if ($lesson->replaces_lesson_id !== null) {
            $this->replacementOriginal($user, $lesson, $lock);
        } elseif ($lesson->replacement()->withTrashed()->whereNull('deleted_at')->when($lock, fn ($q) => $q->lockForUpdate())->first() !== null) {
            $this->invalidReplacement();
        }
    }

    public function prepareReplacement(User $user, Lesson $original, array $values, bool $lock = false): array
    {
        $this->invalidReplacement();
    }

    private function replacementOriginal(User $user, Lesson $replacement, bool $lock = true): Lesson
    {
        if ($replacement->replaces_lesson_id === $replacement->id
            || ! in_array($replacement->status, [LessonStatus::Active, LessonStatus::Cancelled], true)
            || $replacement->replacement()->withTrashed()->when($lock, fn ($q) => $q->lockForUpdate())->first() !== null) {
            $this->invalidReplacement();
        }
        $original = $user->lessons()->withTrashed()->whereKey($replacement->replaces_lesson_id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if ($original === null || $original->trashed() || $original->status !== LessonStatus::Replaced
            || $original->replaces_lesson_id !== null) {
            $this->invalidReplacement();
        }

        return $original;
    }

    private function invalidReplacement(): never
    {
        throw ValidationException::withMessages(['replacement' => ['Заміна цього заняття зараз недоступна.']]);
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

    private function validateCandidate(User $user, array $candidate, LessonStatus $status, ?Lesson $lesson = null, array $excludedIds = [], bool $lock = true): void
    {
        foreach (['subject_id' => 'subjects', 'teacher_id' => 'teachers', 'academic_period_id' => 'academicPeriods'] as $field => $relation) {
            if ($candidate[$field] === null && $field !== 'subject_id') {
                continue;
            }
            if ($user->$relation()->whereKey($candidate[$field])->when($lock, fn ($q) => $q->lockForUpdate())->first() === null) {
                throw ValidationException::withMessages([$field => ['Вибраний зв’язок недоступний.']]);
            }
        }

        if ($lesson?->import_fingerprint !== null && $candidate['academic_period_id'] === null) {
            throw ValidationException::withMessages(['academic_period_id' => ['Для цього заняття потрібен навчальний період.']]);
        }

        if ($candidate['starts_at']->greaterThanOrEqualTo($candidate['ends_at'])) {
            throw ValidationException::withMessages(['ends_at' => ['Час завершення має бути пізніше часу початку.']]);
        }

        if ($status === LessonStatus::Active) {
            $overlap = $user->lessons()->where('status', LessonStatus::Active)
                ->where('starts_at', '<', $candidate['ends_at'])->where('ends_at', '>', $candidate['starts_at'])
                ->when($lesson !== null, fn ($query) => $query->whereKeyNot($lesson->getKey()))
                ->whereNotIn('id', $excludedIds)
                ->when($lock, fn ($q) => $q->lockForUpdate())->first();
            if ($overlap !== null) {
                throw ValidationException::withMessages(['schedule' => ['Заняття перетинається з наявним активним заняттям.']]);
            }

            // The writer already holds the owner lock shared with semester writers.
            // Keep bounded reads for the calendar service, even for a long dated lesson.
            $end = $candidate['ends_at']->getTimestamp();
            try {
                for ($start = $candidate['starts_at']->getTimestamp(); $start < $end; $start = $until) {
                    $until = min($end, $start + 120 * 86400);
                    foreach ($this->calendar->between($user, $start, $until) as $interval) {
                        // All dated Lessons (including the edited row's exclusions) were
                        // checked above; calendar selection also handles semester exceptions.
                        if ($interval['source_type'] === 'semester_occurrence') {
                            throw ValidationException::withMessages(['schedule' => ['Заняття перетинається з чинним семестровим заняттям.']]);
                        }
                    }
                }
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['schedule' => ['Не вдалося перевірити чинний розклад. Перевірте семестровий розклад і часовий пояс перед збереженням.']]);
            }
        }
    }
}
