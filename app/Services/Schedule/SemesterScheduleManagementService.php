<?php

namespace App\Services\Schedule;

use App\Models\ScheduleDateExceptionLesson;
use App\Models\SemesterSchedule;
use App\Models\User;
use App\Services\Imports\SemesterScheduleImportPlanner;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SemesterScheduleManagementService
{
    private const LESSON_FIELDS = ['pair_number', 'pair_label', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room'];

    public function __construct(
        private SemesterScheduleStateService $state,
        private EffectiveScheduleCalculator $calculator,
        private SemesterScheduleImportPlanner $planner,
    ) {}

    public function read(User $user, SemesterSchedule $schedule): array
    {
        return DB::transaction(function () use ($user, $schedule): array {
            $owner = User::findOrFail($user->id);
            $target = $owner->semesterSchedules()->findOrFail($schedule->id);
            $graph = $this->state->load($owner, $target->academic_period_id);

            return $this->result($owner, $graph);
        });
    }

    public function create(User $user, array $values): array
    {
        return DB::transaction(function () use ($user, $values): array {
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $graph = $this->state->load($owner, $values['academic_period_id'], true);
            if ($graph['schedule'] !== null) {
                $this->invalid('academic_period_id', 'A semester schedule already exists for this academic period.');
            }
            $candidate = new SemesterSchedule(['user_id' => $owner->id, ...Arr::only($values,
                ['academic_period_id', 'group_name', 'parity_anchor_on', 'first_week_parity'])]);
            $graph['schedule'] = $candidate;
            $this->validate($owner, $graph);
            $this->check($candidate->save());

            return $this->result($owner, $this->state->load($owner, $candidate->academic_period_id, true));
        });
    }

    public function update(User $user, SemesterSchedule $schedule, array $values): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph) use ($values): bool {
            $graph['schedule']->fill(Arr::only($values, ['group_name', 'parity_anchor_on', 'first_week_parity']));
            $this->validate($owner, $graph);
            if ($graph['schedule']->isDirty()) {
                $this->check($graph['schedule']->save());

                return true;
            }

            return false;
        });
    }

    public function delete(User $user, SemesterSchedule $schedule, array $values): void
    {
        DB::transaction(function () use ($user, $schedule, $values): void {
            [$owner, $graph] = $this->locked($user, $schedule, $values['schedule_token']);
            if ($graph['rules']->isNotEmpty() || $graph['exceptions']->isNotEmpty() || $graph['suppressions']->isNotEmpty()
                || $owner->semesterScheduleImports()->where('academic_period_id', $graph['period']->id)->lockForUpdate()->exists()) {
                $this->invalid('semester_schedule', 'Remove rules, date exceptions and suppressed slots explicitly first. A schedule with semester import history cannot be deleted.');
            }
            $this->validate($owner, $graph);
            $this->check($graph['schedule']->delete());
        });
    }

    public function createRule(User $user, SemesterSchedule $schedule, array $values): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph) use ($values): void {
            $fields = $this->planner->values($values);
            $this->references($graph, $fields);
            $this->notSuppressed($graph, $fields);
            $rule = $graph['schedule']->weeklyLessonRules()->make(['user_id' => $owner->id, ...$fields, 'is_manually_managed' => true]);
            $rule->id = -1;
            $candidate = $graph;
            $candidate['rules'] = $graph['rules']->concat([$rule]);
            $this->validate($owner, $candidate);
            unset($rule->id);
            $this->check($rule->save());
        });
    }

    public function updateRule(User $user, SemesterSchedule $schedule, int $id, array $values): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph) use ($id, $values): bool {
            $rule = $graph['rules']->find($id) ?? abort(404);
            $before = $this->planner->values($rule->attributesToArray());
            $fields = array_replace($before, Arr::only($values, SemesterScheduleImportPlanner::FIELDS));
            $this->references($graph, $fields);
            if ($this->planner->key($before) !== $this->planner->key($fields)) {
                $this->notSuppressed($graph, $fields);
            }
            $rule->fill($fields);
            $this->validate($owner, $graph);
            if ($before !== $fields) {
                if ($rule->semester_schedule_import_id !== null && $this->planner->key($fields) !== $rule->import_key) {
                    $this->suppress($owner, $graph, $rule->import_baseline);
                }
                $rule->is_manually_managed = true;
                $this->check($rule->save());

                return true;
            }

            return false;
        });
    }

    public function deleteRule(User $user, SemesterSchedule $schedule, int $id, array $values): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph) use ($id): void {
            $rule = $graph['rules']->find($id) ?? abort(404);
            $candidate = $graph;
            $candidate['rules'] = $graph['rules']->reject(fn ($row) => $row->id === $id);
            $this->validate($owner, $candidate);
            $this->suppress($owner, $graph, $rule->attributesToArray());
            if ($rule->semester_schedule_import_id !== null) {
                $this->suppress($owner, $graph, $rule->import_baseline);
            }
            foreach ($graph['lessons']->where('source_weekly_rule_id', $id) as $lesson) {
                $this->check($lesson->update(['source_weekly_rule_id' => null]));
            }
            $this->check($rule->delete());
        });
    }

    public function releaseSuppression(User $user, SemesterSchedule $schedule, int $id, array $values): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph) use ($id): void {
            $slot = $graph['suppressions']->find($id) ?? abort(404);
            $this->validate($owner, $graph);
            $this->check($slot->delete());
        });
    }

    public function replaceDate(User $user, SemesterSchedule $schedule, string $date, array $values): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph) use ($date, $values): void {
            $this->within($graph, $date);
            $existing = $graph['exceptions']->first(fn ($row) => $row->occurs_on->format('Y-m-d') === $date);
            $oldLessons = $graph['lessons']->where('schedule_date_exception_id', $existing?->id);
            $lessons = [];
            if ($values['mode'] === 'copy_base') {
                if ($existing?->kind === 'working_saturday') {
                    $this->invalid('mode', 'Edit a working Saturday with a complete replacement lesson list.');
                }
                $base = $this->calculator->calculate($this->state->config($graph), $graph['rules']->map->attributesToArray()->all(), null, $date, $owner->timezone);
                $changes = collect($values['changes'] ?? [])->keyBy('weekly_lesson_rule_id');
                foreach ($base as $row) {
                    $rule = $graph['rules']->find($row['weekly_lesson_rule_id']);
                    $change = $changes->pull($rule->id, []);
                    if ($change['cancelled'] ?? false) {
                        continue;
                    }
                    $lessons[] = [...array_replace($rule->only(self::LESSON_FIELDS), Arr::only($change, self::LESSON_FIELDS)),
                        'source_weekly_rule_id' => $rule->id];
                }
                if ($changes->isNotEmpty()) {
                    $this->invalid('changes', 'Every changed rule must occur in the selected base day.');
                }
            }
            foreach ($values['lessons'] ?? [] as $row) {
                $old = isset($row['id']) ? $oldLessons->find($row['id']) : null;
                if (isset($row['id']) && ($old === null || $values['mode'] !== 'replace')) {
                    $this->invalid('lessons', 'A lesson ID must belong to this saved date snapshot.');
                }
                $lessons[] = [...$this->lessonFields($row), 'id' => $old?->id,
                    'source_weekly_rule_id' => $old?->source_weekly_rule_id, 'created_at' => $old?->getRawOriginal('created_at')];
            }
            $this->saveSnapshot($owner, $graph, $date, $existing?->kind ?? 'replacement', $existing?->copied_weekday, $lessons, $existing);
        });
    }

    public function workingSaturday(User $user, SemesterSchedule $schedule, array $values): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph) use ($values): void {
            $date = $values['occurs_on'];
            $this->within($graph, $date);
            if ((new DateTimeImmutable($date.' UTC'))->format('N') !== '6') {
                $this->invalid('occurs_on', 'A working Saturday must be a Saturday.');
            }
            if ($graph['exceptions']->contains(fn ($row) => $row->occurs_on->format('Y-m-d') === $date)) {
                throw new ConflictHttpException('A date exception already exists. Edit or delete it explicitly.');
            }
            [$monday] = $this->calculator->weekDates($date);
            $source = (new DateTimeImmutable($monday.' UTC'))->modify('+'.($values['copied_weekday'] - 1).' days')->format('Y-m-d');
            $this->within($graph, $source);
            $effective = $this->calculator->calculate($this->state->config($graph), $graph['rules']->map->attributesToArray()->all(), $graph['snapshots'][$source] ?? null, $source, $owner->timezone);
            $lessons = [];
            foreach ($effective as $row) {
                $origin = $row['weekly_lesson_rule_id'] !== null ? $graph['rules']->find($row['weekly_lesson_rule_id'])
                    : $graph['lessons']->where('schedule_date_exception_id', $graph['snapshots'][$source]['id'])->firstWhere('pair_number', $row['pair_number']);
                $lessons[] = [...$origin->only(self::LESSON_FIELDS),
                    'source_weekly_rule_id' => $row['weekly_lesson_rule_id'] ?? $origin->source_weekly_rule_id];
            }
            $this->saveSnapshot($owner, $graph, $date, 'working_saturday', $values['copied_weekday'], $lessons);
        });
    }

    public function deleteException(User $user, SemesterSchedule $schedule, string $date, array $values): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph) use ($date): void {
            $exception = $graph['exceptions']->first(fn ($row) => $row->occurs_on->format('Y-m-d') === $date) ?? abort(404);
            $candidate = $graph;
            unset($candidate['snapshots'][$date]);
            $this->validate($owner, $candidate);
            $this->check($exception->delete());
        });
    }

    private function saveSnapshot(User $owner, array $graph, string $date, string $kind, ?int $copied, array $lessons, $existing = null): void
    {
        foreach ($lessons as $index => &$lesson) {
            $this->references($graph, $lesson);
            $lesson['id'] ??= -($index + 1);
        }
        unset($lesson);
        $candidate = $graph;
        $candidate['snapshots'][$date] = ['id' => $existing?->id ?? 0, 'occurs_on' => $date, 'kind' => $kind,
            'copied_weekday' => $copied, 'lessons' => $lessons];
        $this->validate($owner, $candidate);
        $exception = $existing ?? $graph['schedule']->dateExceptions()->make(['user_id' => $owner->id,
            'occurs_on' => $date, 'kind' => $kind, 'copied_weekday' => $copied]);
        if (! $exception->exists) {
            $this->check($exception->save());
        }
        // Bounded full-day rewrite permits pair swaps under the unique pair FK/index.
        // Retained row IDs and created_at values come only from this owned snapshot.
        foreach ($graph['lessons']->where('schedule_date_exception_id', $exception->id) as $old) {
            $this->check($old->delete());
        }
        foreach ($lessons as $lesson) {
            $model = new ScheduleDateExceptionLesson(['user_id' => $owner->id, 'semester_schedule_id' => $graph['schedule']->id,
                'schedule_date_exception_id' => $exception->id, ...$this->lessonFields($lesson),
                'source_weekly_rule_id' => $lesson['source_weekly_rule_id'] ?? null]);
            if ($lesson['id'] > 0) {
                $model->id = $lesson['id'];
                $model->created_at = $lesson['created_at'];
            }
            $this->check($model->save());
        }
    }

    private function lessonFields(array $row): array
    {
        return array_replace(['pair_label' => null, 'teacher_id' => null, 'room' => null], Arr::only($row, self::LESSON_FIELDS));
    }

    private function within(array $graph, string $date): void
    {
        $this->calculator->validateDate($date);
        if ($date < $graph['period']->starts_on->format('Y-m-d') || $date > $graph['period']->ends_on->format('Y-m-d')) {
            $this->invalid('date', 'The date must be within the academic period.');
        }
    }

    private function write(User $user, SemesterSchedule $schedule, array $values, Closure $operation): array
    {
        return DB::transaction(function () use ($user, $schedule, $values, $operation): array {
            [$owner, $graph] = $this->locked($user, $schedule, $values['schedule_token']);
            if ($operation($owner, $graph) !== false) {
                $graph['schedule']->management_revision++;
                $this->check($graph['schedule']->save());
            }

            return $this->result($owner, $this->state->load($owner, $graph['period']->id, true));
        });
    }

    private function locked(User $user, SemesterSchedule $schedule, string $token): array
    {
        $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $target = $owner->semesterSchedules()->findOrFail($schedule->id);
        $graph = $this->state->load($owner, $target->academic_period_id, true);
        if (! hash_equals($this->state->fingerprint($owner, $graph), $token)) {
            throw new ConflictHttpException('The semester schedule changed. Reload its management state before retrying.');
        }

        return [$owner, $graph];
    }

    private function validate(User $owner, array $graph): void
    {
        try {
            $this->calculator->validateSemester($this->state->config($graph), $graph['rules']->map->attributesToArray()->all(), $graph['snapshots'], $owner->timezone);
        } catch (InvalidArgumentException) {
            $this->invalid('schedule', 'The projected semester contains duplicate pairs, overlapping times, invalid dates or ambiguous/nonexistent local endpoints.');
        }
    }

    private function references(array $graph, array $row): void
    {
        if (! $graph['subjects']->contains('id', $row['subject_id'])) {
            $this->invalid('subject_id', 'The selected subject must belong to you.');
        }
        if (($row['teacher_id'] ?? null) !== null && ! $graph['teachers']->contains('id', $row['teacher_id'])) {
            $this->invalid('teacher_id', 'The selected teacher must belong to you.');
        }
    }

    private function notSuppressed(array $graph, array $fields): void
    {
        if ($this->planner->blockingSuppressions($fields, $graph['suppressions']->map->attributesToArray()->all()) !== []) {
            $this->invalid('suppressed_slot', 'Release this intentionally removed slot before creating or moving a rule into it.');
        }
    }

    private function suppress(User $owner, array $graph, array $fields): void
    {
        $slot = $graph['schedule']->suppressedSlots()->firstOrNew(Arr::only($fields, ['weekday', 'pair_number', 'week_pattern']));
        if (! $slot->exists) {
            $slot->user_id = $owner->id;
            $this->check($slot->save());
        }
    }

    private function result(User $owner, array $graph): array
    {
        $graph['schedule']->setRelation('academicPeriod', $graph['period']);
        foreach ($graph['rules']->concat($graph['lessons']) as $lesson) {
            $lesson->setRelation('subject', $graph['subjects']->find($lesson->subject_id));
            $lesson->setRelation('teacher', $graph['teachers']->find($lesson->teacher_id));
        }
        foreach ($graph['exceptions'] as $exception) {
            $exception->setRelation('lessons', $graph['lessons']->where('schedule_date_exception_id', $exception->id)->values());
        }
        $graph['meta'] = ['schedule_token' => $this->state->fingerprint($owner, $graph),
            'semester_schedule_id' => $graph['schedule']->id, 'timezone' => $owner->timezone];

        return $graph;
    }

    private function check(bool $success): void
    {
        if (! $success) {
            throw new RuntimeException('The semester management write failed.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
