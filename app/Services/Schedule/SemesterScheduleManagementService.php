<?php

namespace App\Services\Schedule;

use App\Enums\LessonType;
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
    private const LESSON_FIELDS = ['pair_number', 'pair_label', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'teacher_ids', 'room', 'lesson_type'];

    public function __construct(
        private SemesterScheduleStateService $state,
        private EffectiveScheduleCalculator $calculator,
        private SemesterScheduleImportPlanner $planner,
        private SemesterLessonTeacherService $lessonTeachers,
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

    public function update(User $user, SemesterSchedule $schedule, array $values, bool $previewOnly = false): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph, bool $previewOnly) use ($values) {
            $graph['schedule']->fill(Arr::only($values, ['group_name', 'parity_anchor_on', 'first_week_parity']));
            $this->validate($owner, $graph);
            if ($previewOnly) {
                return $graph;
            }
            if ($graph['schedule']->isDirty()) {
                $this->check($graph['schedule']->save());

                return true;
            }

            return false;
        }, $previewOnly);
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

    public function createRule(User $user, SemesterSchedule $schedule, array $values, bool $previewOnly = false): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph, bool $previewOnly) use ($values) {
            $this->lessonTeachers->teacherId($values);
            $fields = $this->planner->values($values);
            $fields['lesson_type'] = $values['lesson_type'] ?? null;
            $this->references($graph, $fields);
            $this->notSuppressed($graph, $fields);
            $rule = $graph['schedule']->weeklyLessonRules()->make(['user_id' => $owner->id, ...Arr::except($fields, ['teacher_ids']), 'is_manually_managed' => true]);
            $rule->setRelation('teachers', $graph['teachers']->whereIn('id', $fields['teacher_ids'])->values());
            $rule->id = -1;
            $candidate = $graph;
            $candidate['rules'] = $graph['rules']->concat([$rule]);
            $this->validate($owner, $candidate);
            if ($previewOnly) {
                return $candidate;
            }
            unset($rule->id);
            $this->check($rule->save());
            $this->lessonTeachers->sync($rule, $fields['teacher_id']);
        }, $previewOnly);
    }

    public function updateRule(User $user, SemesterSchedule $schedule, int $id, array $values, bool $previewOnly = false): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph, bool $previewOnly) use ($id, $values) {
            $rule = $graph['rules']->find($id) ?? abort(404);
            $before = [...$this->planner->values($rule->attributesToArray()), 'lesson_type' => $rule->lesson_type?->value];
            $merged = $this->mergeLessonFields($before, Arr::only($values, [...SemesterScheduleImportPlanner::FIELDS, 'lesson_type']));
            $this->lessonTeachers->teacherId($merged);
            $fields = $this->planner->values($merged);
            $fields['lesson_type'] = $merged['lesson_type'] ?? null;
            $this->references($graph, $fields);
            if ($this->planner->key($before) !== $this->planner->key($fields)) {
                $this->notSuppressed($graph, $fields);
            }
            $rule->fill(Arr::except($fields, ['teacher_ids']));
            $rule->setRelation('teachers', $graph['teachers']->whereIn('id', $fields['teacher_ids'])->values());
            $this->validate($owner, $graph);
            if ($previewOnly) {
                return $graph;
            }
            if ($before !== $fields) {
                if ($rule->semester_schedule_import_id !== null && $this->planner->key($fields) !== $rule->import_key) {
                    $this->suppress($owner, $graph, $rule->import_baseline);
                }
                $rule->is_manually_managed = true;
                $this->check($rule->save());
                $this->lessonTeachers->sync($rule, $fields['teacher_id']);

                return true;
            }

            return false;
        }, $previewOnly);
    }

    public function deleteRule(User $user, SemesterSchedule $schedule, int $id, array $values, bool $previewOnly = false): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph, bool $previewOnly) use ($id) {
            $rule = $graph['rules']->find($id) ?? abort(404);
            $candidate = $graph;
            $candidate['rules'] = $graph['rules']->reject(fn ($row) => $row->id === $id);
            $this->validate($owner, $candidate);
            if ($previewOnly) {
                return $candidate;
            }
            $this->suppress($owner, $graph, $rule->attributesToArray());
            if ($rule->semester_schedule_import_id !== null) {
                $this->suppress($owner, $graph, $rule->import_baseline);
            }
            foreach ($graph['lessons']->where('source_weekly_rule_id', $id) as $lesson) {
                $this->check($lesson->update(['source_weekly_rule_id' => null]));
            }
            $this->check($rule->delete());
        }, $previewOnly);
    }

    public function releaseSuppression(User $user, SemesterSchedule $schedule, int $id, array $values): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph) use ($id): void {
            $slot = $graph['suppressions']->find($id) ?? abort(404);
            $this->validate($owner, $graph);
            $this->check($slot->delete());
        });
    }

    /** Change one effective occurrence while preserving every other lesson on that date. */
    public function changeOccurrence(User $user, SemesterSchedule $schedule, string $date, string $identity,
        array $patch, bool $cancelled, array $values, bool $previewOnly = false): array
    {
        return DB::transaction(function () use ($user, $schedule, $date, $identity, $patch, $cancelled, $values, $previewOnly): array {
            $owner = User::findOrFail($user->id);
            $target = $owner->semesterSchedules()->findOrFail($schedule->id);
            $graph = $this->state->load($owner, $target->academic_period_id);
            $this->within($graph, $date);
            $snapshot = $graph['snapshots'][$date] ?? null;
            $occurrences = $this->calculator->calculate($this->state->config($graph),
                $graph['rules']->map->attributesToArray()->all(), $snapshot, $date, $owner->timezone);
            $occurrence = collect($occurrences)->firstWhere('occurrence_id', $identity);
            if ($occurrence === null) {
                abort(404);
            }
            if ($snapshot === null) {
                $replacement = ['mode' => 'copy_base', 'changes' => [[
                    'weekly_lesson_rule_id' => $occurrence['weekly_lesson_rule_id'],
                    ...Arr::only($patch, self::LESSON_FIELDS), 'cancelled' => $cancelled,
                ]]];
            } else {
                $lessons = [];
                foreach ($snapshot['lessons'] as $row) {
                    $selected = $identity === 'semester:'.$target->id.':'.$date.':snapshot:'.$row['id'];
                    if ($selected && $cancelled) {
                        continue;
                    }
                    $lessons[] = ['id' => $row['id'], ...Arr::only($selected
                        ? $this->mergeLessonFields($row, $patch) : $row, self::LESSON_FIELDS)];
                }
                $replacement = ['mode' => 'replace', 'lessons' => $lessons];
            }

            return $this->replaceDate($owner, $target, $date, [...$replacement,
                'schedule_token' => $values['schedule_token']], $previewOnly);
        });
    }

    /** Append one date-only lesson to the effective day, without replacing saved rows. */
    public function createOccurrence(User $user, SemesterSchedule $schedule, string $date, array $values, bool $previewOnly = false): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph, bool $previewOnly) use ($date, $values) {
            $this->within($graph, $date);
            $existing = $graph['exceptions']->first(fn ($row) => $row->occurs_on->format('Y-m-d') === $date);
            $snapshot = $graph['snapshots'][$date] ?? null;
            $effective = $this->calculator->calculate($this->state->config($graph),
                $graph['rules']->map->attributesToArray()->all(), $snapshot, $date, $owner->timezone);
            if ($existing !== null && $effective === []) {
                $this->invalid('date', 'This date is cancelled. Restore or replace it explicitly before adding a lesson.');
            }
            $lessons = $snapshot['lessons'] ?? array_map(function ($row) use ($graph): array {
                $rule = $graph['rules']->find($row['weekly_lesson_rule_id']);

                return [...$this->lessonFields($rule->only(self::LESSON_FIELDS)),
                    'source_weekly_rule_id' => $rule->id, 'is_added' => false];
            }, $effective);
            $added = [...$this->lessonFields(Arr::only($values, self::LESSON_FIELDS)),
                'source_weekly_rule_id' => null, 'is_added' => true];
            $lessons[] = $added;
            // Use the same full projected-semester validation as manual date edits.
            $candidate = $this->saveSnapshot($owner, $graph, $date, $existing?->kind ?? 'replacement',
                $existing?->copied_weekday, $lessons, $existing, previewOnly: true);
            if ($previewOnly) {
                return $candidate;
            }
            if ($existing === null) {
                return $this->saveSnapshot($owner, $graph, $date, 'replacement', null, $lessons);
            }
            // Existing snapshot identities, timestamps, teacher links and Saturday metadata are untouched.
            $lesson = new ScheduleDateExceptionLesson(['user_id' => $owner->id, 'semester_schedule_id' => $graph['schedule']->id,
                'schedule_date_exception_id' => $existing->id, ...Arr::except($added, ['teacher_ids'])]);
            $this->check($lesson->save());
            $this->lessonTeachers->sync($lesson, $added['teacher_id']);
        }, $previewOnly);
    }

    public function replaceDate(User $user, SemesterSchedule $schedule, string $date, array $values, bool $previewOnly = false): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph, bool $previewOnly) use ($date, $values) {
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
                    $lessons[] = [...$this->lessonFields($this->mergeLessonFields($rule->only(self::LESSON_FIELDS), Arr::only($change, self::LESSON_FIELDS))),
                        'source_weekly_rule_id' => $rule->id, 'is_added' => false];
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
                if ($old !== null && ! array_key_exists('lesson_type', $row)) {
                    $row['lesson_type'] = $old->lesson_type?->value;
                }
                $lessons[] = [...$this->lessonFields($row), 'id' => $old?->id,
                    'source_weekly_rule_id' => $old?->source_weekly_rule_id,
                    'is_added' => $old === null ? true : $old->is_added, 'created_at' => $old?->getRawOriginal('created_at')];
            }

            return $this->saveSnapshot($owner, $graph, $date, $existing?->kind ?? 'replacement', $existing?->copied_weekday, $lessons, $existing, previewOnly: $previewOnly);
        }, $previewOnly);
    }

    public function workingSaturday(User $user, SemesterSchedule $schedule, array $values, bool $previewOnly = false): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph, bool $previewOnly) use ($values) {
            $date = $values['occurs_on'];
            $this->within($graph, $date);
            if ((new DateTimeImmutable($date.' UTC'))->format('N') !== '6') {
                $this->invalid('occurs_on', 'A working Saturday must be a Saturday.');
            }
            if ($graph['exceptions']->contains(fn ($row) => $row->occurs_on->format('Y-m-d') === $date)) {
                throw new ConflictHttpException('A date exception already exists. Edit or delete it explicitly.');
            }
            if (array_key_exists('source_week_parity', $values) || array_key_exists('selected_weekly_rule_ids', $values)) {
                $parity = $values['source_week_parity'] ?? null;
                $selected = $values['selected_weekly_rule_ids'] ?? [];
                if (! in_array($parity, ['numerator', 'denominator'], true)) {
                    $this->invalid('source_week_parity', 'Select numerator or denominator together with the lesson IDs.');
                }
                if (! is_array($selected) || ! array_is_list($selected) || $selected === []
                    || count($selected) !== count(array_unique($selected, SORT_REGULAR))
                    || ! collect($selected)->every(fn ($id) => is_int($id) && $id > 0)) {
                    $this->invalid('selected_weekly_rule_ids', 'Select a nonempty list of unique positive weekly rule IDs.');
                }
                $eligible = $graph['rules']->filter(fn ($rule) => $rule->weekday === $values['copied_weekday']
                    && in_array($rule->week_pattern, ['all', $parity], true));
                if (array_diff($selected, $eligible->modelKeys()) !== []) {
                    $this->invalid('selected_weekly_rule_ids', 'Every selected rule must belong to this semester and match the chosen weekday and parity.');
                }
                $lessons = $eligible->whereIn('id', $selected)->map(fn ($rule) => [
                    ...$rule->only(self::LESSON_FIELDS), 'source_weekly_rule_id' => $rule->id, 'is_added' => false,
                ])->values()->all();
                $lessons = array_map($this->normalizeLessonType(...), $lessons);

                return $this->saveSnapshot($owner, $graph, $date, 'working_saturday', $values['copied_weekday'], $lessons, sourceWeekParity: $parity, previewOnly: $previewOnly);
            }
            // Compatibility mode copies the effective source date, including replacements/cancellation.
            [$monday] = $this->calculator->weekDates($date);
            $source = (new DateTimeImmutable($monday.' UTC'))->modify('+'.($values['copied_weekday'] - 1).' days')->format('Y-m-d');
            $this->within($graph, $source);
            $effective = $this->calculator->calculate($this->state->config($graph), $graph['rules']->map->attributesToArray()->all(), $graph['snapshots'][$source] ?? null, $source, $owner->timezone);
            $lessons = [];
            foreach ($effective as $row) {
                $origin = $row['weekly_lesson_rule_id'] !== null ? $graph['rules']->find($row['weekly_lesson_rule_id'])
                    : $graph['lessons']->where('schedule_date_exception_id', $graph['snapshots'][$source]['id'])->firstWhere('pair_number', $row['pair_number']);
                $lessons[] = [...$origin->only(self::LESSON_FIELDS),
                    'source_weekly_rule_id' => $row['weekly_lesson_rule_id'] ?? $origin->source_weekly_rule_id,
                    'is_added' => $row['weekly_lesson_rule_id'] !== null ? false : $origin->is_added];
            }
            $lessons = array_map($this->normalizeLessonType(...), $lessons);

            return $this->saveSnapshot($owner, $graph, $date, 'working_saturday', $values['copied_weekday'], $lessons,
                sourceWeekParity: $this->calculator->weekParity($this->state->config($graph), $source), previewOnly: $previewOnly);
        }, $previewOnly);
    }

    public function deleteException(User $user, SemesterSchedule $schedule, string $date, array $values, bool $previewOnly = false): array
    {
        return $this->write($user, $schedule, $values, function ($owner, $graph, bool $previewOnly) use ($date) {
            $exception = $graph['exceptions']->first(fn ($row) => $row->occurs_on->format('Y-m-d') === $date) ?? abort(404);
            $candidate = $graph;
            unset($candidate['snapshots'][$date]);
            $this->validate($owner, $candidate);
            if ($previewOnly) {
                return $candidate;
            }
            $this->check($exception->delete());
        }, $previewOnly);
    }

    private function saveSnapshot(User $owner, array $graph, string $date, string $kind, ?int $copied, array $lessons, $existing = null, ?string $sourceWeekParity = null, bool $previewOnly = false): ?array
    {
        foreach ($lessons as $index => &$lesson) {
            $this->references($graph, $lesson);
            $lesson['id'] ??= -($index + 1);
        }
        unset($lesson);
        $candidate = $graph;
        $candidate['snapshots'][$date] = ['id' => $existing?->id ?? 0, 'occurs_on' => $date, 'kind' => $kind,
            'copied_weekday' => $copied, 'source_week_parity' => $existing?->source_week_parity ?? $sourceWeekParity, 'lessons' => $lessons];
        $this->validate($owner, $candidate);
        if ($previewOnly) {
            return $candidate;
        }
        $exception = $existing ?? $graph['schedule']->dateExceptions()->make(['user_id' => $owner->id,
            'occurs_on' => $date, 'kind' => $kind, 'copied_weekday' => $copied, 'source_week_parity' => $sourceWeekParity]);
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
                'schedule_date_exception_id' => $exception->id, ...Arr::except($this->lessonFields($lesson), ['teacher_ids']),
                'source_weekly_rule_id' => $lesson['source_weekly_rule_id'] ?? null,
                'is_added' => $lesson['is_added'] ?? null]);
            if ($lesson['id'] > 0) {
                $model->id = $lesson['id'];
                $model->created_at = $lesson['created_at'];
            }
            $this->check($model->save());
            $this->lessonTeachers->sync($model, $lesson['teacher_id']);
        }

        return null;
    }

    private function lessonFields(array $row): array
    {
        $fields = array_replace(['pair_label' => null, 'teacher_id' => null, 'room' => null, 'lesson_type' => null], Arr::only($row, self::LESSON_FIELDS));
        $fields = $this->normalizeLessonType($fields);
        $fields['teacher_id'] = $this->lessonTeachers->teacherId($fields);
        $fields['teacher_ids'] = [$fields['teacher_id']];

        return $fields;
    }

    private function normalizeLessonType(array $row): array
    {
        if ($row['lesson_type'] instanceof LessonType) {
            $row['lesson_type'] = $row['lesson_type']->value;
        }

        return $row;
    }

    private function mergeLessonFields(array $before, array $patch): array
    {
        // Scalar teacher_id is canonical; discard only an omitted, cached compatibility list.
        if (array_key_exists('teacher_id', $patch) && ! array_key_exists('teacher_ids', $patch)) {
            unset($before['teacher_ids']);
        }

        return array_replace($before, $patch);
    }

    private function within(array $graph, string $date): void
    {
        $this->calculator->validateDate($date);
        if ($date < $graph['period']->starts_on->format('Y-m-d') || $date > $graph['period']->ends_on->format('Y-m-d')) {
            $this->invalid('date', 'The date must be within the academic period.');
        }
    }

    private function write(User $user, SemesterSchedule $schedule, array $values, Closure $operation, bool $previewOnly = false): array
    {
        return DB::transaction(function () use ($user, $schedule, $values, $operation, $previewOnly): array {
            if ($previewOnly) {
                $owner = User::findOrFail($user->id);
                $target = $owner->semesterSchedules()->findOrFail($schedule->id);
                $graph = $this->state->load($owner, $target->academic_period_id);
                if (! hash_equals($this->state->fingerprint($owner, $graph), $values['schedule_token'])) {
                    throw new ConflictHttpException('The semester schedule changed.');
                }

                return $operation($owner, $graph, true);
            }
            [$owner, $graph] = $this->locked($user, $schedule, $values['schedule_token']);
            if ($operation($owner, $graph, false) !== false) {
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
        $definitions = $graph['rules']->map->attributesToArray()->all();
        foreach ($graph['snapshots'] as $snapshot) {
            array_push($definitions, ...$snapshot['lessons']);
        }
        if ($this->lessonTeachers->assignmentErrors($definitions, $graph['teacherAssignments']) !== []) {
            $this->invalid('teacher_id', 'A subject cannot have conflicting teacher assignments in the projected semester.');
        }
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
        $teacherId = $this->lessonTeachers->teacherId($row);
        if (! $graph['teachers']->contains('id', $teacherId)) {
            $this->invalid('teacher_id', 'The selected teacher must belong to you.');
        }
        if (isset($graph['teacherAssignments'][$row['subject_id']]) && $graph['teacherAssignments'][$row['subject_id']] !== $teacherId) {
            $this->invalid('teacher_id', 'The subject is already assigned to another teacher.');
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
