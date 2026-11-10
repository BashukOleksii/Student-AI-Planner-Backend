<?php

namespace App\Services\Schedule;

use App\Models\AcademicPeriod;
use App\Models\ScheduleDateExceptionLesson;
use App\Models\User;
use App\Services\Imports\SemesterScheduleImportPlanner;
use Illuminate\Validation\ValidationException;

/** Shared authoritative graph for consistent management reads and owner-locked writes. */
class SemesterScheduleStateService
{
    public function __construct(private SemesterScheduleImportPlanner $planner) {}

    public function load(User $owner, int $periodId, bool $lock = false): array
    {
        $period = $this->period($owner, $periodId, $lock);
        $subjects = $owner->subjects()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $teachers = $owner->teachers()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $schedule = $owner->semesterSchedules()->where('academic_period_id', $period->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $rules = $schedule?->weeklyLessonRules()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get() ?? collect();
        $exceptions = $schedule?->dateExceptions()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get() ?? collect();
        foreach ($exceptions as $exception) {
            if ($exception->user_id !== $owner->id) {
                $this->invalidGraph();
            }
        }
        $lessons = ScheduleDateExceptionLesson::whereIn('schedule_date_exception_id', $exceptions->pluck('id'))
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        foreach ($rules->concat($lessons) as $rule) {
            if ($rule->user_id !== $owner->id || $rule->semester_schedule_id !== $schedule->id
                || ! $subjects->contains('id', $rule->subject_id)
                || ($rule->teacher_id !== null && ! $teachers->contains('id', $rule->teacher_id))) {
                $this->invalidGraph();
            }
        }
        $suppressions = $schedule?->suppressedSlots()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get() ?? collect();
        foreach ($suppressions as $slot) {
            if ($slot->user_id !== $owner->id) {
                $this->invalidGraph();
            }
        }
        $provenance = [];
        $sources = $owner->semesterScheduleImports()->whereIn('id', $rules->pluck('semester_schedule_import_id')->filter())
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $legacySources = $owner->scheduleImportBatches()->whereIn('id', $rules->pluck('source_import_batch_id')->filter())
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        foreach ($rules as $rule) {
            if ($rule->semester_schedule_import_id !== null) {
                $source = $sources->get($rule->semester_schedule_import_id);
                if ($source === null || $source->academic_period_id !== $period->id || $source->status !== 'committed'
                    || ! is_array($rule->import_baseline) || count($rule->import_baseline) !== count(SemesterScheduleImportPlanner::FIELDS)
                    || array_diff(array_keys($rule->import_baseline), SemesterScheduleImportPlanner::FIELDS) !== []
                    || $rule->import_key !== $this->planner->key($rule->import_baseline)) {
                    $this->invalidGraph();
                }
                $provenance[$source->id] = [$source->id, $source->academic_period_id, $source->status];
            }
            if ($rule->source_import_batch_id !== null) {
                $source = $legacySources->get($rule->source_import_batch_id);
                if ($source === null || $source->academic_period_id !== $period->id) {
                    $this->invalidGraph();
                }
            }
        }
        $snapshots = [];
        foreach ($exceptions as $exception) {
            $children = $lessons->where('schedule_date_exception_id', $exception->id);
            foreach ($children as $lesson) {
                if ($lesson->source_weekly_rule_id !== null && ! $rules->contains('id', $lesson->source_weekly_rule_id)) {
                    $this->invalidGraph();
                }
            }
            $date = $exception->occurs_on->format('Y-m-d');
            $snapshots[$date] = ['id' => $exception->id, 'occurs_on' => $date, 'kind' => $exception->kind,
                'copied_weekday' => $exception->copied_weekday, 'lessons' => $children->map->attributesToArray()->values()->all()];
        }

        return compact('period', 'subjects', 'teachers', 'schedule', 'rules', 'exceptions', 'lessons', 'suppressions', 'provenance', 'snapshots');
    }

    public function config(array $state): array
    {
        $schedule = $state['schedule'];

        return ['id' => $schedule->id, 'starts_on' => $state['period']->starts_on->format('Y-m-d'),
            'ends_on' => $state['period']->ends_on->format('Y-m-d'),
            'parity_anchor_on' => $schedule->parity_anchor_on->format('Y-m-d'), 'first_week_parity' => $schedule->first_week_parity];
    }

    public function fingerprint(User $owner, array $state): string
    {
        return $this->hash(['timezone' => $owner->timezone, 'period' => $state['period']->getAttributes(),
            'institution' => $state['period']->educationInstitution->getAttributes(),
            'subjects' => $state['subjects']->map->getAttributes()->all(), 'teachers' => $state['teachers']->map->getAttributes()->all(),
            'schedule' => $state['schedule']?->getAttributes(), 'rules' => $state['rules']->map->getAttributes()->all(),
            'exceptions' => $state['exceptions']->map->getAttributes()->all(), 'lessons' => $state['lessons']->map->getAttributes()->all(),
            'suppressions' => $state['suppressions']->map->getAttributes()->all(), 'provenance' => $state['provenance']]);
    }

    public function period(User $owner, int $id, bool $lock = false): AcademicPeriod
    {
        $period = $owner->academicPeriods()->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $institution = $period === null ? null : $owner->educationInstitutions()->whereKey($period->education_institution_id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if ($period === null || $institution === null) {
            throw ValidationException::withMessages(['academic_period_id' => ['The selected period must belong to you and have an owned education institution.']]);
        }
        $period->setRelation('educationInstitution', $institution);

        return $period;
    }

    public function hash(mixed $value): string
    {
        $canonical = function (mixed $item) use (&$canonical): mixed {
            if (! is_array($item)) {
                return $item;
            }
            $item = array_map($canonical, $item);
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return $item;
        };

        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function invalidGraph(): never
    {
        throw ValidationException::withMessages(['semester_schedule' => ['The saved schedule or import data is inconsistent. Repair it before changing the schedule.']]);
    }
}
