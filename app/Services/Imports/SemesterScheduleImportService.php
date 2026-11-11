<?php

namespace App\Services\Imports;

use App\Exceptions\ScheduleWorkbookParserException;
use App\Models\SemesterScheduleImport;
use App\Models\User;
use App\Services\Schedule\EffectiveScheduleCalculator;
use App\Services\Schedule\SemesterLessonTeacherService;
use App\Services\Schedule\SemesterScheduleStateService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class SemesterScheduleImportService
{
    public function __construct(
        private ScheduleWorkbookParser $parser,
        private ScheduleOccurrenceResolver $resolver,
        private SemesterScheduleImportPlanner $planner,
        private EffectiveScheduleCalculator $calculator,
        private SemesterScheduleStateService $state,
        private SemesterLessonTeacherService $lessonTeachers,
    ) {}

    public function create(User $user, array $options, UploadedFile $file): SemesterScheduleImport
    {
        $this->state->period($user, $options['academic_period_id']);
        $template = null;
        $error = null;
        try {
            $template = $this->parser->parse($file->getPathname(), semester: true);
        } catch (ScheduleWorkbookParserException $exception) {
            $error = $exception->getMessage();
        }
        $hash = hash_file('sha256', $file->getPathname());
        if ($hash === false) {
            throw new RuntimeException('Unable to hash the workbook.');
        }
        $filename = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $filename = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?: 'schedule.xlsx', 0, 255);

        return DB::transaction(function () use ($user, $options, $template, $error, $hash, $filename): SemesterScheduleImport {
            $owner = $this->owner($user);
            $period = $this->state->period($owner, $options['academic_period_id'], true);
            $import = $owner->semesterScheduleImports()->create([
                'academic_period_id' => $period->id, 'parity_anchor_on' => $this->calculator->weekDates($period->starts_on->format('Y-m-d'))[0],
                'first_week_parity' => 'numerator', 'original_filename' => $filename, 'file_hash' => $hash,
                'template' => $template, 'status' => $error === null ? 'invalid' : 'failed',
                'review' => ['rows' => [], 'operations' => [], 'counts' => [], 'errors' => $error === null ? [] : ['file' => [$error]]],
                'preview_token' => bin2hex(random_bytes(32)),
            ]);
            $this->check($import->exists);
            if ($error === null) {
                [$review, $context] = $this->buildReview($owner, $import);
                $this->saveReview($import, $review, $context);
            }

            return $import;
        });
    }

    public function revalidate(User $user, SemesterScheduleImport $import): SemesterScheduleImport
    {
        return DB::transaction(function () use ($user, $import): SemesterScheduleImport {
            $owner = $this->owner($user);
            $import = $owner->semesterScheduleImports()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if (! in_array($import->status, ['ready', 'invalid'], true) || $import->template === null) {
                $this->invalid('Only an uncommitted parsed import can be revalidated.');
            }
            [$review, $context] = $this->buildReview($owner, $import);
            $this->saveReview($import, $review, $context);

            return $import;
        });
    }

    /** Confirmation returns a durable receipt, or refreshes ONLY the stale preview. */
    public function commit(User $user, SemesterScheduleImport $import, string $token): array
    {
        return DB::transaction(function () use ($user, $import, $token): array {
            $owner = $this->owner($user);
            $import = $owner->semesterScheduleImports()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($import->preview_token, $token)) {
                return ['import' => $import, 'changed' => true];
            }
            if ($import->status === 'committed') {
                return ['import' => $import, 'changed' => false];
            }
            if ($import->status !== 'ready') {
                $this->invalid('This import preview is not ready to commit.');
            }
            [$review, $context, $graph] = $this->buildReview($owner, $import);
            if (! hash_equals($import->context_hash, $context) || $this->state->hash($import->review) !== $this->state->hash($review)) {
                $this->saveReview($import, $review, $context);

                return ['import' => $import, 'changed' => true];
            }
            if ($this->hasErrors($review)) {
                $this->invalid('This import preview is not ready to commit.');
            }
            // Only after locked revalidation and token comparison may catalog writes begin.
            [$catalog, $identities] = $this->commitCatalog($owner, $review['catalog'], $graph['period']->education_institution_id);
            $schedule = $graph['schedule'];
            $values = $review['impact']['schedule']['after'];
            if ($schedule === null) {
                $schedule = $owner->semesterSchedules()->create(['academic_period_id' => $import->academic_period_id, ...$values]);
                $this->check($schedule->exists);
            } elseif ($review['impact']['schedule']['before'] !== $values) {
                $this->check($schedule->update($values));
            }
            // Remove reviewed old identities first, without losing independent snapshots.
            foreach ($review['operations'] as $operation) {
                if ($operation['operation'] !== 'delete') {
                    continue;
                }
                $rule = $graph['rules']->find($operation['rule_id']);
                foreach ($graph['lessons']->where('source_weekly_rule_id', $rule->id) as $lesson) {
                    $this->check($lesson->update(['source_weekly_rule_id' => null]));
                }
                $this->check($rule->delete());
            }
            foreach ($review['operations'] as $operation) {
                if (! in_array($operation['operation'], ['create', 'update'], true)) {
                    continue;
                }
                $after = $operation['after'];
                $after['subject_id'] = $identities['subjects'][$after['subject_id']];
                $after['teacher_id'] = $identities['teachers'][$after['teacher_id']];
                $after['teacher_ids'] = [$after['teacher_id']];
                $after = $this->planner->values($after);
                $fields = [...Arr::except($after, ['teacher_ids']), 'user_id' => $owner->id, 'semester_schedule_import_id' => $import->id,
                    'import_key' => $operation['import_key'], 'import_baseline' => $after];
                if ($operation['operation'] === 'create') {
                    $rule = $schedule->weeklyLessonRules()->create($fields);
                    $this->check($rule->exists);
                } else {
                    $rule = $graph['rules']->find($operation['rule_id']);
                    $this->check($rule->update($fields));
                }
                $this->lessonTeachers->sync($rule, $after['teacher_id']);
            }
            if ($graph['schedule'] !== null && ($review['impact']['schedule']['before'] !== $values
                || $review['counts']['create'] + $review['counts']['update'] + $review['counts']['delete'] > 0)) {
                $schedule->management_revision++;
                $this->check($schedule->save());
            }
            $now = CarbonImmutable::now('UTC');
            $receipt = ['semester_schedule_id' => $schedule->id, 'counts' => $review['counts'], 'catalog' => $catalog,
                'parity_anchor_on' => $values['parity_anchor_on'], 'first_week_parity' => $values['first_week_parity'],
                'rule_ids' => $schedule->weeklyLessonRules()->orderBy('id')->pluck('id')->all(),
                'committed_at' => $now->format('Y-m-d\TH:i:s.u\Z')];
            $this->check($import->update(['status' => 'committed', 'receipt' => $receipt, 'committed_at' => $now]));

            return ['import' => $import, 'changed' => false];
        });
    }

    private function buildReview(User $owner, SemesterScheduleImport $import): array
    {
        $graph = $this->state->load($owner, $import->academic_period_id, true);
        ['period' => $period, 'subjects' => $subjects, 'teachers' => $teachers, 'schedule' => $schedule,
            'rules' => $rules, 'exceptions' => $exceptions, 'lessons' => $lessons, 'snapshots' => $snapshots,
            'suppressions' => $suppressions] = $graph;
        $template = $import->template;
        if (! is_array($template) || ! is_string($template['group'] ?? null) || ! is_array($template['rules'] ?? null)) {
            $this->invalidGraph();
        }
        $teacherNames = [];
        foreach ($template['rules'] as &$raw) {
            // Old saved templates retain their original shape and are upgraded only in memory.
            $raw['teacher_names'] ??= $this->parser->teacherNames($raw['teacher_name'] ?? null);
            array_push($teacherNames, ...$raw['teacher_names']);
        }
        unset($raw);
        $references = $this->resolver->resolveSemesterNames(array_column($template['rules'], 'subject_name'), $teacherNames, $subjects, $teachers);
        $catalog = [];
        foreach ($references as $kind => $entries) {
            $catalog[$kind] = collect($entries)->unique('id')->values()->all();
        }
        $incoming = [];
        foreach ($template['rules'] as $index => $raw) {
            $subject = $references['subjects'][$raw['subject_name']];
            $rowTeachers = collect($raw['teacher_names'])->map(fn ($name) => $references['teachers'][$name])->unique('id')->sortBy('id')->values();
            $errors = [];
            if (count($raw['teacher_names']) !== 1) {
                $errors['teacher_name'] = ['Exactly one teacher name is required for each semester lesson.'];
            }
            if ($subject['errors'] !== []) {
                $errors['subject_name'] = $subject['errors'];
            }
            foreach ($rowTeachers as $teacher) {
                if ($teacher['errors'] !== []) {
                    $errors['teacher_names'][] = ['name' => $teacher['name'], 'errors' => $teacher['errors']];
                }
            }
            if ($raw['room'] !== null && mb_strlen($raw['room']) > 100) {
                $errors['room'] = ['The room must not exceed 100 characters.'];
            }
            $incoming[] = [...$raw, 'row_number' => $index + 1, 'pair_number' => $this->planner->pairNumber($raw['pair_label']),
                'week_pattern' => $raw['parity'], 'subject_id' => $subject['id'],
                'teacher_id' => count($raw['teacher_names']) === 1 ? $rowTeachers->first()['id'] : null, 'teacher_ids' => $rowTeachers->pluck('id')->all(),
                'subject' => $subject, 'teacher' => count($raw['teacher_names']) === 1 ? $rowTeachers->first() : null, 'teachers' => $rowTeachers->all(), 'errors' => $errors];
        }
        foreach ($this->lessonTeachers->assignmentErrors($incoming, $graph['teacherAssignments']) as $index => $message) {
            $incoming[$index]['errors']['teacher_id'] = [$message];
        }
        $plan = $this->planner->plan($incoming, $rules->map->attributesToArray()->all(), $suppressions->map->attributesToArray()->all());
        if (mb_strlen($template['group']) > 255) {
            $plan['errors']['group_name'] = ['The group name must not exceed 255 characters.'];
        }
        $after = ['group_name' => $template['group'], 'parity_anchor_on' => $this->calculator->weekDates($period->starts_on->format('Y-m-d'))[0], 'first_week_parity' => 'numerator'];
        $before = $schedule === null ? null : ['group_name' => $schedule->group_name,
            'parity_anchor_on' => $schedule->parity_anchor_on->format('Y-m-d'), 'first_week_parity' => $schedule->first_week_parity];
        try {
            $this->calculator->validateSemester(['id' => $schedule?->id ?? 0, 'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d'), ...$after], $plan['projected_rules'], $snapshots, $owner->timezone);
        } catch (InvalidArgumentException) {
            $plan['errors']['schedule'] = ['The projected schedule has conflicting or invalid rules, exceptions or local timezone endpoints.'];
        }
        $deleted = array_column(array_filter($plan['operations'], fn ($op) => $op['operation'] === 'delete'), 'rule_id');
        $detach = $lessons->whereIn('source_weekly_rule_id', $deleted)->map(fn ($lesson) => [
            'snapshot_lesson_id' => $lesson->id, 'source_weekly_rule_id' => $lesson->source_weekly_rule_id,
            'occurs_on' => $exceptions->find($lesson->schedule_date_exception_id)->occurs_on->format('Y-m-d'),
        ])->values()->all();
        $review = ['catalog' => $catalog, 'rows' => $plan['rows'], 'operations' => $plan['operations'], 'counts' => $plan['counts'], 'errors' => $plan['errors'],
            'impact' => ['schedule' => ['id' => $schedule?->id, 'before' => $before, 'after' => $after],
                'preserved_rule_ids' => $rules->filter(fn ($rule) => ! $this->planner->managed($rule->attributesToArray()))->pluck('id')->values()->all(),
                'preserved_exception_dates' => array_keys($snapshots),
                'suppressed_slots' => $suppressions->map->only(['id', 'weekday', 'pair_number', 'week_pattern'])->all(), 'detached_snapshot_references' => $detach]];
        $context = $this->state->hash(['template' => $template, 'options' => $after,
            'graph' => $this->state->fingerprint($owner, $graph)]);

        return [$review, $context, compact('schedule', 'rules', 'lessons', 'period')];
    }

    private function commitCatalog(User $owner, array $catalog, int $institutionId): array
    {
        $receipt = $identities = ['subjects' => [], 'teachers' => []];
        foreach ($catalog as $kind => $entries) {
            foreach ($entries as $entry) {
                $id = $entry['id'];
                if ($entry['resolution'] === 'create') {
                    $record = $owner->{$kind}()->create(['name' => $entry['name'], 'education_institution_id' => $institutionId]);
                    $this->check($record->exists);
                    $id = $record->id;
                }
                $identities[$kind][$entry['id']] = $id;
                $receipt[$kind][] = [...Arr::except($entry, ['errors']), 'id' => $id,
                    'resolution' => $entry['resolution'] === 'create' ? 'created' : 'reused'];
            }
        }

        return [$receipt, $identities];
    }

    private function saveReview(SemesterScheduleImport $import, array $review, string $context): void
    {
        $this->check($import->update(['review' => $review, 'context_hash' => $context,
            'parity_anchor_on' => $review['impact']['schedule']['after']['parity_anchor_on'], 'first_week_parity' => 'numerator',
            'status' => $this->hasErrors($review) ? 'invalid' : 'ready', 'preview_token' => bin2hex(random_bytes(32))]));
    }

    private function hasErrors(array $review): bool
    {
        return $review['errors'] !== [] || collect($review['rows'])->contains(fn ($row) => $row['errors'] !== []);
    }

    private function owner(User $user): User
    {
        return User::whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    private function check(bool $success): void
    {
        if (! $success) {
            throw new RuntimeException('The semester import write failed.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['semester_import' => [$message]]);
    }

    private function invalidGraph(): never
    {
        $this->invalid('The saved schedule or import data is inconsistent. Repair it before importing.');
    }
}
