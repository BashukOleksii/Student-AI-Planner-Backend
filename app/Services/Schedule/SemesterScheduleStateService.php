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
    public function __construct(private SemesterScheduleImportPlanner $planner, private SemesterLessonTeacherService $lessonTeachers) {}

    public function load(User $owner, int $periodId, bool $lock = false): array
    {
        $period = $this->period($owner, $periodId, $lock);
        $subjects = $owner->subjects()->with(['teachers' => fn ($query) => $query->when($lock, fn ($q) => $q->lockForUpdate())])->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $teachers = $owner->teachers()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $schedule = $owner->semesterSchedules()->where('academic_period_id', $period->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $rules = $schedule?->weeklyLessonRules()->with(['teachers' => fn ($query) => $query->when($lock, fn ($q) => $q->lockForUpdate())])->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get() ?? collect();
        $exceptions = $schedule?->dateExceptions()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get() ?? collect();
        foreach ($exceptions as $exception) {
            if ($exception->user_id !== $owner->id) {
                $this->invalidGraph();
            }
        }
        $lessons = ScheduleDateExceptionLesson::with(['teachers' => fn ($query) => $query->when($lock, fn ($q) => $q->lockForUpdate())])->whereIn('schedule_date_exception_id', $exceptions->pluck('id'))
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        foreach ($rules->concat($lessons) as $rule) {
            $this->lessonTeachers->assertHistoricalLesson($rule);
            if ($rule->user_id !== $owner->id || $rule->semester_schedule_id !== $schedule->id
                || ! $subjects->contains('id', $rule->subject_id)
                || array_diff($rule->teacher_ids, $teachers->modelKeys()) !== []
                || $rule->teachers->contains(fn ($teacher) => $teacher->pivot->user_id !== $owner->id)) {
                $this->invalidGraph();
            }
        }
        $teacherAssignments = $this->lessonTeachers->catalogAssignments($owner, $subjects, $lock);
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
                    || ! $this->validBaseline($rule->import_baseline)
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
                'copied_weekday' => $exception->copied_weekday, 'source_week_parity' => $exception->source_week_parity, 'lessons' => $children->map->attributesToArray()->values()->all()];
        }

        return compact('period', 'subjects', 'teachers', 'schedule', 'rules', 'exceptions', 'lessons', 'suppressions', 'provenance', 'snapshots', 'teacherAssignments');
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
        return $this->hash(['teacher_semantics' => 'single', 'teacher_assignments' => $state['teacherAssignments'], 'timezone' => $owner->timezone, 'period' => $state['period']->getAttributes(),
            'institution' => $state['period']->educationInstitution->getAttributes(),
            'subjects' => $state['subjects']->map->getAttributes()->all(), 'teachers' => $state['teachers']->map->getAttributes()->all(),
            'subject_teachers' => $state['subjects']->map(fn ($subject) => [$subject->id, $subject->teachers->modelKeys()])->all(),
            'schedule' => $state['schedule']?->getAttributes(), 'rules' => $state['rules']->map(fn ($rule) => [...$rule->getAttributes(), 'teacher_ids' => $rule->teacher_ids, 'teacher_links' => $rule->teachers->modelKeys()])->all(),
            'exceptions' => $state['exceptions']->map->getAttributes()->all(), 'lessons' => $state['lessons']->map(fn ($lesson) => [...$lesson->getAttributes(), 'teacher_ids' => $lesson->teacher_ids, 'teacher_links' => $lesson->teachers->modelKeys()])->all(),
            'suppressions' => $state['suppressions']->map->getAttributes()->all(), 'provenance' => $state['provenance']]);
    }

    public function period(User $owner, int $id, bool $lock = false): AcademicPeriod
    {
        $period = $owner->academicPeriods()->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $institution = $period === null ? null : $owner->educationInstitutions()->whereKey($period->education_institution_id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if ($period === null || $institution === null) {
            throw ValidationException::withMessages(['academic_period_id' => ['Виберіть свій навчальний період, пов’язаний із вашим навчальним закладом.']]);
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

    private function validBaseline(mixed $baseline): bool
    {
        if (! is_array($baseline)) {
            return false;
        }
        $fields = array_key_exists('teacher_ids', $baseline) ? SemesterScheduleImportPlanner::FIELDS : SemesterScheduleImportPlanner::LEGACY_FIELDS;

        return count($baseline) === count($fields) && array_diff(array_keys($baseline), $fields) === []
            && (! array_key_exists('teacher_ids', $baseline) || (is_array($baseline['teacher_ids'])
                && array_is_list($baseline['teacher_ids'])
                && collect($baseline['teacher_ids'])->every(fn ($id) => is_int($id) && $id > 0)));
    }

    private function invalidGraph(): never
    {
        throw ValidationException::withMessages(['semester_schedule' => ['Збережений розклад або дані імпорту неузгоджені. Виправте їх перед зміною розкладу.']]);
    }
}
