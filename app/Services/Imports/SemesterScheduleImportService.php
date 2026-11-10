<?php

namespace App\Services\Imports;

use App\Exceptions\ScheduleWorkbookParserException;
use App\Models\SemesterScheduleImport;
use App\Models\User;
use App\Services\Schedule\EffectiveScheduleCalculator;
use App\Services\Schedule\SemesterScheduleStateService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
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
    ) {}

    public function create(User $user, array $options, UploadedFile $file): SemesterScheduleImport
    {
        $this->state->period($user, $options['academic_period_id']);
        $template = null;
        $error = null;
        try {
            $template = $this->parser->parse($file->getPathname());
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
            $this->state->period($owner, $options['academic_period_id'], true);
            $import = $owner->semesterScheduleImports()->create([
                'academic_period_id' => $options['academic_period_id'], 'parity_anchor_on' => $options['parity_anchor_on'],
                'first_week_parity' => $options['first_week_parity'], 'original_filename' => $filename, 'file_hash' => $hash,
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
                $fields = [...$operation['after'], 'user_id' => $owner->id, 'semester_schedule_import_id' => $import->id,
                    'import_key' => $operation['import_key'], 'import_baseline' => $operation['after']];
                if ($operation['operation'] === 'create') {
                    $this->check($schedule->weeklyLessonRules()->create($fields)->exists);
                } else {
                    $this->check($graph['rules']->find($operation['rule_id'])->update($fields));
                }
            }
            if ($graph['schedule'] !== null && ($review['impact']['schedule']['before'] !== $values
                || $review['counts']['create'] + $review['counts']['update'] + $review['counts']['delete'] > 0)) {
                $schedule->management_revision++;
                $this->check($schedule->save());
            }
            $now = CarbonImmutable::now('UTC');
            $receipt = ['semester_schedule_id' => $schedule->id, 'counts' => $review['counts'],
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
        $references = $this->resolver->resolveNames($owner, array_column($template['rules'], 'subject_name'), array_column($template['rules'], 'teacher_name'), true);
        $incoming = [];
        foreach ($template['rules'] as $index => $raw) {
            $subject = $references['subjects'][$raw['subject_name']];
            $teacher = $references['teachers'][$raw['teacher_name']];
            $errors = [];
            if ($subject['errors'] !== []) {
                $errors['subject_name'] = $subject['errors'];
            }
            if ($teacher['errors'] !== []) {
                $errors['teacher_name'] = $teacher['errors'];
            }
            if ($raw['room'] !== null && mb_strlen($raw['room']) > 100) {
                $errors['room'] = ['The room must not exceed 100 characters.'];
            }
            $incoming[] = [...$raw, 'row_number' => $index + 1, 'pair_number' => $this->planner->pairNumber($raw['pair_label']),
                'week_pattern' => $raw['parity'], 'subject_id' => $subject['id'], 'teacher_id' => $teacher['id'],
                'subject' => $subjects->find($subject['id'])?->only(['id', 'name', 'code', 'color']),
                'teacher' => $teachers->find($teacher['id'])?->only(['id', 'name']), 'errors' => $errors];
        }
        $plan = $this->planner->plan($incoming, $rules->map->attributesToArray()->all(), $suppressions->map->attributesToArray()->all());
        if (mb_strlen($template['group']) > 255) {
            $plan['errors']['group_name'] = ['The group name must not exceed 255 characters.'];
        }
        $after = ['group_name' => $template['group'], 'parity_anchor_on' => $import->parity_anchor_on->format('Y-m-d'), 'first_week_parity' => $import->first_week_parity];
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
        $review = ['rows' => $plan['rows'], 'operations' => $plan['operations'], 'counts' => $plan['counts'], 'errors' => $plan['errors'],
            'impact' => ['schedule' => ['id' => $schedule?->id, 'before' => $before, 'after' => $after],
                'preserved_rule_ids' => $rules->filter(fn ($rule) => ! $this->planner->managed($rule->attributesToArray()))->pluck('id')->values()->all(),
                'preserved_exception_dates' => array_keys($snapshots),
                'suppressed_slots' => $suppressions->map->only(['id', 'weekday', 'pair_number', 'week_pattern'])->all(), 'detached_snapshot_references' => $detach]];
        $context = $this->state->hash(['template' => $template, 'options' => $after,
            'graph' => $this->state->fingerprint($owner, $graph)]);

        return [$review, $context, compact('schedule', 'rules', 'lessons')];
    }

    private function saveReview(SemesterScheduleImport $import, array $review, string $context): void
    {
        $this->check($import->update(['review' => $review, 'context_hash' => $context,
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
