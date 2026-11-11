<?php

namespace App\Services\Schedule;

use App\Models\AcademicPeriod;
use App\Models\ScheduleDateExceptionLesson;
use App\Models\SemesterSchedule;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Owned semester reads; legacy Lesson/planning/import/AI consumers stay separate. */
class EffectiveScheduleService
{
    public function __construct(private EffectiveScheduleCalculator $calculator, private SemesterLessonTeacherService $lessonTeachers) {}

    public function schedules(User $user): Collection
    {
        return DB::transaction(function () use ($user): Collection {
            $schedules = $user->semesterSchedules()->with('academicPeriod.educationInstitution')->orderBy('id')->get();
            foreach ($schedules as $schedule) {
                $this->validatePeriod($user, $schedule);
            }

            return $schedules;
        });
    }

    public function detail(User $user, SemesterSchedule $schedule): SemesterSchedule
    {
        return DB::transaction(fn () => $this->ownedSchedule($user, $schedule));
    }

    // Preserve the foundation's internal list contract.
    public function date(User $user, SemesterSchedule $schedule, string $date): array
    {
        return $this->forDate($user, $schedule, $date)['lessons'];
    }

    public function week(User $user, SemesterSchedule $schedule, string $date): array
    {
        return $this->forWeek($user, $schedule, $date)['lessons'];
    }

    public function forDate(User $user, SemesterSchedule $schedule, string $date): array
    {
        $this->calculator->validateDate($date);

        return $this->range($user, $schedule, $date, $date);
    }

    public function forWeek(User $user, SemesterSchedule $schedule, string $date): array
    {
        [$start, $end] = $this->calculator->weekDates($date);

        return $this->range($user, $schedule, $start, $end);
    }

    private function range(User $user, SemesterSchedule $schedule, string $start, string $end): array
    {
        // Load the entire interval and its catalogs once, in one consistent snapshot.
        return DB::transaction(function () use ($user, $schedule, $start, $end): array {
            $owner = User::query()->findOrFail($user->getKey());
            $schedule = $this->ownedSchedule($owner, $schedule);
            $period = $schedule->academicPeriod;
            $catalog = $owner->subjects()->with('teachers')->orderBy('id')->get();
            $this->lessonTeachers->catalogAssignments($owner, $catalog);
            $rules = $schedule->weeklyLessonRules()->with(['subject', 'teacher', 'teachers', 'sourceImportBatch'])->get();
            $exceptions = $schedule->dateExceptions()->whereBetween('occurs_on', [$start, $end])
                ->with(['lessons.subject', 'lessons.teacher', 'lessons.teachers', 'lessons.sourceWeeklyRule'])->get();
            $subjects = [];
            $teachers = [];
            $remember = function (WeeklyLessonRule|ScheduleDateExceptionLesson $lesson) use ($owner, &$subjects, &$teachers): void {
                $this->validateAssociations($owner, $lesson);
                $this->lessonTeachers->assertHistoricalLesson($lesson);
                $subjects[$lesson->subject_id] = $lesson->subject->only(['id', 'name', 'code', 'color']);
                foreach ($lesson->teachers as $teacher) {
                    $teachers[$teacher->id] = $teacher->only(['id', 'name']);
                }
                if ($lesson->teacher_id !== null) {
                    $teachers[$lesson->teacher_id] = $lesson->teacher->only(['id', 'name']);
                }
            };
            foreach ($rules as $rule) {
                $remember($rule);
                if ($rule->source_import_batch_id !== null
                    && ($rule->sourceImportBatch === null || $rule->sourceImportBatch->user_id !== $owner->id
                        || $rule->sourceImportBatch->academic_period_id !== $period->id)) {
                    throw new InvalidArgumentException('Invalid semester import provenance.');
                }
            }
            $snapshots = [];
            foreach ($exceptions as $exception) {
                if ($exception->user_id !== $owner->id) {
                    throw new InvalidArgumentException('Invalid date exception ownership.');
                }
                foreach ($exception->lessons as $lesson) {
                    $remember($lesson);
                    if ($lesson->semester_schedule_id !== $schedule->id
                        || ($lesson->source_weekly_rule_id !== null
                            && ($lesson->sourceWeeklyRule === null || $lesson->sourceWeeklyRule->semester_schedule_id !== $schedule->id))) {
                        throw new InvalidArgumentException('Invalid snapshot provenance.');
                    }
                }
                $snapshots[$exception->occurs_on->format('Y-m-d')] = [
                    'id' => $exception->id, 'occurs_on' => $exception->occurs_on->format('Y-m-d'),
                    'kind' => $exception->kind, 'copied_weekday' => $exception->copied_weekday, 'source_week_parity' => $exception->source_week_parity,
                    'lessons' => $exception->lessons->map->attributesToArray()->all(),
                ];
            }
            $config = [
                'id' => $schedule->id, 'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d'),
                'parity_anchor_on' => $schedule->parity_anchor_on->format('Y-m-d'),
                'first_week_parity' => $schedule->first_week_parity,
            ];
            $rules = $rules->map->attributesToArray()->all();
            $lessons = [];
            // Nominal dates in UTC encode calendar labels, not fixed-duration local days.
            for ($day = new DateTimeImmutable($start.' UTC'); $day->format('Y-m-d') <= $end; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                foreach ($this->calculator->calculate($config, $rules, $snapshots[$date] ?? null, $date, $owner->timezone) as $row) {
                    $row['subject'] = $subjects[$row['subject_id']];
                    $row['teacher'] = $teachers[$row['teacher_id']] ?? null;
                    $row['teachers'] = array_map(fn ($id) => $teachers[$id], $row['teacher_ids']);
                    $lessons[] = $row;
                }
            }

            return ['lessons' => $lessons, 'meta' => [
                'timezone' => $owner->timezone, 'semester_schedule_id' => $schedule->id,
                'start_date' => $start, 'end_date' => $end,
            ]];
        });
    }

    private function ownedSchedule(User $user, SemesterSchedule $schedule): SemesterSchedule
    {
        $schedule = $user->semesterSchedules()->with('academicPeriod.educationInstitution')->findOrFail($schedule->getKey());
        $this->validatePeriod($user, $schedule);

        return $schedule;
    }

    private function validatePeriod(User $user, SemesterSchedule $schedule): void
    {
        $period = $schedule->academicPeriod;
        if (! $period instanceof AcademicPeriod || $period->user_id !== $user->id
            || $period->educationInstitution === null || $period->educationInstitution->user_id !== $user->id) {
            throw new InvalidArgumentException('A semester schedule requires an owned period and institution.');
        }
    }

    private function validateAssociations(User $owner, WeeklyLessonRule|ScheduleDateExceptionLesson $lesson): void
    {
        if ($lesson->user_id !== $owner->id || $lesson->subject === null || $lesson->subject->user_id !== $owner->id
            || ($lesson->teacher_id !== null && ($lesson->teacher === null || $lesson->teacher->user_id !== $owner->id))
            || $lesson->teachers->contains(fn ($teacher) => $teacher->user_id !== $owner->id || $teacher->pivot->user_id !== $owner->id)) {
            throw new InvalidArgumentException('Invalid semester lesson ownership or academic references.');
        }
    }
}
