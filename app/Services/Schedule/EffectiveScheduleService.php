<?php

namespace App\Services\Schedule;

use App\Models\ScheduleDateExceptionLesson;
use App\Models\SemesterSchedule;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Owned read adapter. The existing REST/planning/import/AI paths do not use it yet. */
class EffectiveScheduleService
{
    public function __construct(private EffectiveScheduleCalculator $calculator) {}

    public function date(User $user, SemesterSchedule $schedule, string $date): array
    {
        // A consistent read snapshot, with no locks, inserts, updates or repairs.
        return DB::transaction(function () use ($user, $schedule, $date): array {
            $owner = User::query()->findOrFail($user->getKey());
            $schedule = $owner->semesterSchedules()->findOrFail($schedule->getKey());
            $this->calculator->validateDate($date);
            $period = $owner->academicPeriods()->findOrFail($schedule->academic_period_id);
            if ($period->education_institution_id === null
                || ! $owner->educationInstitutions()->whereKey($period->education_institution_id)->exists()) {
                throw new InvalidArgumentException('A semester schedule requires an owned education institution through its academic period.');
            }
            $rules = $schedule->weeklyLessonRules()->with(['subject', 'teacher', 'sourceImportBatch'])->get();
            $exception = $schedule->dateExceptions()->where('occurs_on', $date)->with('lessons.subject', 'lessons.teacher', 'lessons.sourceWeeklyRule')->first();
            foreach ($rules as $rule) {
                $this->validateAssociations($owner, $rule);
                if ($rule->source_import_batch_id !== null
                    && ($rule->sourceImportBatch === null || $rule->sourceImportBatch->user_id !== $owner->id
                        || $rule->sourceImportBatch->academic_period_id !== $period->id)) {
                    throw new InvalidArgumentException('Invalid semester import provenance.');
                }
            }
            if ($exception !== null) {
                if ($exception->user_id !== $owner->id) {
                    throw new InvalidArgumentException('Invalid date exception ownership.');
                }
                foreach ($exception->lessons as $lesson) {
                    $this->validateAssociations($owner, $lesson);
                    if ($lesson->source_weekly_rule_id !== null
                        && ($lesson->sourceWeeklyRule === null || $lesson->sourceWeeklyRule->semester_schedule_id !== $schedule->id)) {
                        throw new InvalidArgumentException('Invalid snapshot provenance.');
                    }
                }
            }
            $snapshot = $exception === null ? null : [
                'id' => $exception->id,
                'occurs_on' => $exception->occurs_on->format('Y-m-d'),
                'kind' => $exception->kind,
                'copied_weekday' => $exception->copied_weekday,
                'lessons' => $exception->lessons->map->attributesToArray()->all(),
            ];

            return $this->calculator->calculate([
                'id' => $schedule->id,
                'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d'),
                'parity_anchor_on' => $schedule->parity_anchor_on->format('Y-m-d'),
                'first_week_parity' => $schedule->first_week_parity,
            ], $rules->map->attributesToArray()->all(), $snapshot, $date, $owner->timezone);
        });
    }

    private function validateAssociations(User $owner, WeeklyLessonRule|ScheduleDateExceptionLesson $lesson): void
    {
        if ($lesson->user_id !== $owner->id || $lesson->subject === null || $lesson->subject->user_id !== $owner->id
            || ($lesson->teacher_id !== null && ($lesson->teacher === null || $lesson->teacher->user_id !== $owner->id))) {
            throw new InvalidArgumentException('Invalid semester lesson ownership or academic references.');
        }
    }
}
