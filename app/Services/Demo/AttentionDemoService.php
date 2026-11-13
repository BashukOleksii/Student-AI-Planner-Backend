<?php

namespace App\Services\Demo;

use App\Models\User;
use App\Services\Planning\LearningRecoveryAnalysisService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\PlanningDiagnosticsService;
use App\Services\Planning\RecoverySuggestionService;
use App\Services\Planning\StudyPlanningService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudyReplanningPersistenceService;
use App\Services\Planning\StudySessionAllocationService;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Local fixtures only. Capacity probes reuse the real allocator; reviews come only from the real detector. */
class AttentionDemoService
{
    private const PREFIX = '[DEMO-ATTENTION]';

    public function __construct(private DemoDatabaseGuard $guard, private TaskService $tasks,
        private PlanningContextService $context, private PlanningDiagnosticsService $diagnostics,
        private StudySessionAllocationService $allocator, private StudyPlanPersistenceService $plans,
        private StudyPlanningService $planner, private StudyReplanningPersistenceService $replanning,
        private StudySessionLifecycleService $lifecycle, private LearningRecoveryAnalysisService $analysis,
        private RecoverySuggestionService $detector) {}

    public function run(User $user, string $email, string $key, string $step, bool $dryRun,
        ?string $database = null, ?string $serverUuid = null): array
    {
        if (! in_array($key, range('A', 'H'), true) || ! in_array($step, ['prepare', 'trigger', 'stale', 'check'], true)) {
            throw new RuntimeException('Оберіть один сценарій A–H і крок prepare/trigger/stale/check.');
        }
        $identity = $this->guard->inspect(false);
        if (! $dryRun && ($database !== $identity['database'] || $serverUuid !== $identity['server_uuid'])) {
            throw new RuntimeException('Для запису передайте точні --database та --server-uuid із dry-run.');
        }
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Потрібна окрема owner-first транзакція.');
        }
        $result = DB::transaction(function () use ($user, $email, $key, $step, $dryRun, $identity): array {
            $owner = User::whereKey($user->id)->when(! $dryRun, fn ($q) => $q->lockForUpdate())->firstOrFail();
            if ($owner->email !== $email) {
                throw new RuntimeException('ID та email власника не збігаються. Запис заборонено.');
            }
            $marker = self::PREFIX.' v1 scenario='.$key."\n";
            $matches = $owner->tasks()->withTrashed()->where(fn ($q) => $q->where('description', 'like', $marker.'%')
                ->orWhere('title', 'like', self::PREFIX.' '.$key.' —%'))->get();
            if ($matches->count() > 1 || ($matches->isNotEmpty() && ! str_starts_with($matches->sole()->description ?? '', $marker))) {
                throw new RuntimeException('Неоднозначний або змінений DEMO-маркер. Нічого не відновлено.');
            }
            $task = $matches->first();
            $other = $owner->tasks()->withTrashed()->where(fn ($q) => $q->where('title', 'like', self::PREFIX.'%')
                ->orWhere('description', 'like', self::PREFIX.' v1 scenario=%'))
                ->when($task !== null, fn ($q) => $q->whereKeyNot($task->id))->get()
                ->filter(fn ($row) => (! $row->trashed() && $row->status->isUnfinished())
                    || $owner->studySessions()->where('task_id', $row->id)->where('status', 'planned')->exists())->modelKeys();
            $now = CarbonImmutable::now('UTC');
            $local = $now->setTimezone($owner->timezone);
            $period = [$local->toDateString(), $local->addDays(6)->toDateString()];
            // This is a SELECT-only assessment, never a persisted suggestion or token supplied by the demo.
            $baseline = $this->analysis->analyze($owner, ...$period);
            $out = ['database' => $identity, 'owner' => $owner->only('id', 'name', 'email', 'timezone'),
                'scenario' => $key, 'step' => $step, 'dry_run' => $dryRun, 'written' => false,
                'now_utc' => $now->toIso8601String(), 'automatic_period' => $period,
                'existing_account_kind' => $baseline['kind'],
                'existing_unknown_session_ids' => array_column($baseline['review']['unknown_session_decisions'], 'id'),
                'blocked_by_demo_task_ids' => $other, 'global_changes' => [],
                'note' => 'Один сценарій за раз. Наявні проблеми акаунта не приховуються; decision_required має пріоритет.'];
            if ($task === null) {
                $blueprint = $this->blueprint($owner, $key, $now, $period);
                $out['proposed_records'] = $blueprint;
                $out['ready'] = $blueprint['feasible'] && $other === [] && $step === 'prepare';
                if ($dryRun || ! $out['ready']) {
                    return [...$out, 'task_id' => null, 'subtask_ids' => [], 'sessions' => []];
                }
                $task = $this->tasks->create($owner, ['title' => self::PREFIX.' '.$key.' — '.$blueprint['label'],
                    'description' => $marker.json_encode($blueprint, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'estimated_minutes' => $blueprint['estimated_minutes'], 'deadline_at' => $blueprint['initial_deadline'], 'priority' => 2]);
                if (in_array($key, ['A', 'G'], true)) {
                    // Historical outcomes cannot be seeded by a future-only planner. Only these marked new records are inserted.
                    $source = $owner->studySessions()->create(['task_id' => $task->id,
                        'starts_at' => $blueprint['source']['starts_at'], 'ends_at' => $blueprint['source']['ends_at'],
                        'status' => 'planned', 'actual_minutes' => null, 'completed_at' => null]);
                    if ($key === 'G') {
                        $this->lifecycle->completeWithinTransaction($owner, (string) $source->id, 30, static function (): void {});
                    }
                } elseif ($blueprint['source'] !== null) {
                    $preview = $this->plans->preview($owner, ...[...$blueprint['period'], $task->id, null, $blueprint['source']['start']]);
                    if (count($preview['plan']['sessions']) !== 1 || $preview['plan']['sessions'][0]['start'] !== $blueprint['source']['start']
                        || $preview['plan']['sessions'][0]['duration_seconds'] !== $blueprint['duration_seconds']) {
                        throw new RuntimeException('Календар змінився: початкова валідна сесія не підтверджена. Усе відкочено.');
                    }
                    $this->plans->confirmWithinTransaction($owner, $preview['preview_token']);
                }
                $out['written'] = true;
                $out['state'] = 'prepared';
            } else {
                $blueprint = json_decode(substr($task->description, strlen($marker)), true, flags: JSON_THROW_ON_ERROR);
                $out['state'] = 'preserved';
                $out['ready'] = true;
                if (in_array($step, ['trigger', 'stale'], true)) {
                    if ($other !== [] || ! in_array($key, ['B', 'C', 'D', 'E', 'H'], true) || ($step === 'stale' && $key !== 'H')) {
                        throw new RuntimeException('Цей крок недоступний для сценарію або інший DEMO ще активний.');
                    }
                    $target = $step === 'trigger' ? $blueprint['trigger_deadline'] : $blueprint['stale_deadline'];
                    $prior = $step === 'trigger' ? $blueprint['initial_deadline'] : $blueprint['trigger_deadline'];
                    $current = $task->deadline_at?->utc()->format('Y-m-d\TH:i:s\Z');
                    $source = $owner->studySessions()->where('task_id', $task->id)->sole();
                    if ($current !== $target) {
                        if ($task->trashed() || $task->status->value !== 'pending' || $current !== $prior
                            || $task->estimated_minutes !== $blueprint['estimated_minutes'] || $source->status->value !== 'planned'
                            || $source->starts_at->timestamp !== $blueprint['source']['start'] || $source->ends_at->timestamp !== $blueprint['source']['end']) {
                            throw new RuntimeException('Результати або демодані вже змінено вручну. Крок не перезаписує їх.');
                        }
                        $out['proposed_task_change'] = ['task_id' => $task->id, 'deadline_before' => $current, 'deadline_after' => $target,
                            'revert' => 'Повернення попереднього дедлайну через редагування лише цього DEMO-завдання; старий огляд усе одно перевірте заново.'];
                        if (! $dryRun) {
                            if (CarbonImmutable::parse($target)->lessThanOrEqualTo($now->addSeconds(900))) {
                                throw new RuntimeException('Контрольована дата вже близько або минула. Не змінюйте результати; підготуйте сценарій іншим запуском після ручної перевірки.');
                            }
                            $task = $this->tasks->update($owner, $task, ['deadline_at' => $target]);
                            $review = $this->replanning->preview($owner, ...[...$blueprint['period'], $task->id]);
                            $row = collect($review['session_results'])->firstWhere('study_session_id', $source->id);
                            if ($step === 'trigger' && (! $row || ! $this->matches($key, $row['replacements'], $blueprint['duration_seconds']))) {
                                throw new RuntimeException('Поточні умови не відтворили очікуваний випадок. Зміну дедлайну відкочено.');
                            }
                            $out['written'] = true;
                            $out['state'] = $step;
                        }
                    }
                }
            }
            $out['manifest'] = $blueprint;
            $out['task_id'] = $task->id;
            $out['subtask_ids'] = $task->subtasks()->withTrashed()->pluck('id')->all();
            $out['task_status'] = $task->status->value;
            $out['sessions'] = $owner->studySessions()->where('task_id', $task->id)->orderBy('id')->get()->map(fn ($s) => [
                'id' => $s->id, 'status' => $s->status->value, 'starts_at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at->toIso8601String(), 'local_start' => $s->starts_at->copy()->setTimezone($owner->timezone)->format('Y-m-d H:i:s'),
                'actual_minutes' => $s->actual_minutes])->all();
            if (! $task->trashed()) {
                $plan = $this->planner->plan($owner, ...[...$blueprint['period'], $task->id]);
                $out['manual_plan_summary'] = $plan['summary'];
                $out['manual_proposed_sessions'] = $this->intervals($plan['sessions'], $owner);
                $out['manual_period'] = $blueprint['period'];
            }

            return $out;
        });
        if (! $dryRun && ($result['written'] || $step === 'check') && $result['task_id'] !== null) {
            // Only the real detector writes reviews/scan state. A repeated prepare never replaces test results.
            try {
                $detected = DB::transaction(function () use ($user, $email): array {
                    $verified = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    if ($verified->email !== $email) {
                        throw new RuntimeException('Власник змінився перед перевіркою.');
                    }
                    $outcome = $this->detector->detectWithinTransaction($verified->id, 7);
                    $latest = $verified->recoverySuggestions()->where('status', 'pending')->latest('created_at')->first();

                    return ['detector_outcome' => $outcome,
                        'automatic_suggestion' => $latest?->only('id', 'kind', 'status', 'start_date', 'end_date')];
                });
                $result = [...$result, ...$detected];
            } catch (\Throwable $exception) {
                report($exception);
                $result['detector_error'] = 'Демодані збережено, але штатна перевірка не завершилася. Повторіть --step=check; сесії не змінено перевіркою.';
            }
        }

        return $result;
    }

    private function blueprint(User $owner, string $key, CarbonImmutable $now, array $period): array
    {
        $labels = ['A' => 'Потрібно вказати результат', 'B' => 'Повне перенесення', 'C' => 'Перенесення з поділом',
            'D' => 'Часткове перенесення', 'E' => 'Заміни немає', 'F' => 'Можна створити план', 'G' => 'Відновлення роботи', 'H' => 'Огляд застарів'];
        $cutoff = $now->timestamp + 900;
        $manual = $key === 'F' ? [$period[0], $now->setTimezone($owner->timezone)->addDays(30)->toDateString()] : $period;
        $context = $this->context->load($owner, ...$manual);
        $base = ['feasible' => false, 'label' => $labels[$key], 'period' => $manual, 'automatic_period' => $period,
            'prepared_at' => $now->toIso8601String(), 'estimated_minutes' => 60, 'initial_deadline' => null,
            'source' => null, 'duration_seconds' => 0, 'trigger_deadline' => null, 'stale_deadline' => null,
            'global_changes' => [], 'task_id' => null, 'subtask_ids' => []];
        if (in_array($key, ['A', 'G'], true)) {
            $start = $now->setTimezone($owner->timezone)->subDays(8)->startOfMinute()->utc()->timestamp;
            $duration = $key === 'A' ? 1800 : 3600;
            $probe = $this->probe($context, $key === 'A' ? 3600 : 5400, null, $cutoff);

            return [...$base, 'feasible' => $key === 'A' || $probe['sessions'] !== [], 'estimated_minutes' => $key === 'A' ? 60 : 120,
                'source' => [...$this->intervals([['start' => $start, 'end' => $start + $duration]], $owner)[0], 'status' => $key === 'A' ? 'planned' : 'completed', 'actual_minutes' => $key === 'A' ? null : 30],
                'duration_seconds' => $duration, 'expected_additional_sessions' => $this->intervals($probe['sessions'], $owner),
                'note' => 'Минулий запис не закривається детектором. Для G 30 фактичних хвилин залишаються незмінними.'];
        }
        if ($key === 'F') {
            $estimate = min(65535, max(60, $context['preferences']['max_session_minutes'] ?? 120) * 5);
            $probe = $this->probe($context, $estimate * 60, null, $cutoff);

            return [...$base, 'feasible' => count($probe['sessions']) >= 4, 'estimated_minutes' => $estimate,
                'expected_additional_sessions' => $this->intervals($probe['sessions'], $owner), 'expected_summary' => $probe['summary'],
                'note' => 'Початкових сесій не створює. Ручний період 31 день; автоматичний — 7 днів, його пріоритет залежить від усього акаунта.'];
        }
        $slots = $this->diagnostics->analyze($owner, ...$period)['slots'];
        $minimum = max(60, ($context['preferences']['min_session_minutes'] ?? 1) * 60);
        foreach ($slots as $slot) {
            $candidate = $slot['candidate'];
            if (! $slot['can_schedule'] || $candidate['start'] < $cutoff) {
                continue;
            }
            foreach (array_unique([$minimum, intdiv($candidate['end'] - $candidate['start'], 60) * 60]) as $duration) {
                if ($duration < $minimum || $duration > $candidate['end'] - $candidate['start']) {
                    continue;
                }
                foreach (array_unique([$candidate['start'], $candidate['start'] + $minimum]) as $deadline) {
                    if ($deadline >= $candidate['start'] + $duration) {
                        continue;
                    }
                    $probe = $this->probe($context, $duration, $deadline, $cutoff);
                    if (! $this->matches($key, $probe['sessions'], $duration)) {
                        continue;
                    }
                    if ($key === 'C') {
                        // No valid single placement can cover the original duration before this deadline.
                        $singleExists = false;
                        foreach ($context['free_intervals'] as $free) {
                            $one = [...$context, 'range' => $free, 'free_intervals' => [$free]];
                            if (($this->probe($one, $duration, $deadline, $cutoff, true)['summary']['allocated_seconds']) >= $duration) {
                                $singleExists = true;
                            }
                        }
                        if ($singleExists) {
                            continue;
                        }
                    }
                    $source = $this->intervals([['start' => $candidate['start'], 'end' => $candidate['start'] + $duration]], $owner)[0];

                    return [...$base, 'feasible' => true, 'estimated_minutes' => intdiv($duration, 60), 'source' => $source,
                        'duration_seconds' => $duration, 'trigger_deadline' => gmdate('Y-m-d\TH:i:s\Z', $deadline),
                        'stale_deadline' => isset($probe['sessions'][0]) ? gmdate('Y-m-d\TH:i:s\Z', $probe['sessions'][0]['start']) : null,
                        'expected_replacements' => $this->intervals($probe['sessions'], $owner),
                        'untransferred_seconds' => $probe['summary']['unscheduled_seconds'],
                        'note' => 'Спочатку planner зберігає одну валідну сесію. Крок trigger змінює лише дедлайн цього DEMO-завдання через TaskService.'];
                }
            }
        }

        return [...$base, 'note' => 'Чинні слоти, ліміти та дедлайни не дозволили цей випадок без зміни глобальних умов. Нічого не створювати.'];
    }

    private function probe(array $context, int $seconds, ?int $deadline, int $cutoff, bool $single = false): array
    {
        // Private synthetic identity is never persisted, exposed as a real ID or used for authorization.
        return $this->allocator->allocate([['task_id' => PHP_INT_MAX, 'subtask_id' => null,
            'effective_deadline_utc' => $deadline, 'effort' => ['unallocated_work_seconds' => $seconds]]], $context, $cutoff, $single);
    }

    private function matches(string $key, array $rows, int $seconds): bool
    {
        $total = array_sum(array_map(fn ($r) => $r['duration_seconds'] ?? $r['end'] - $r['start'], $rows));

        return match ($key) {
            'B', 'H' => count($rows) === 1 && $total === $seconds,
            'C' => count($rows) >= 2 && $total === $seconds,
            'D' => $total > 0 && $total < $seconds,
            'E' => $total === 0,
            default => false,
        };
    }

    private function intervals(array $rows, User $owner): array
    {
        return array_map(fn ($row) => ['start' => $row['start'], 'end' => $row['end'],
            'starts_at' => gmdate('Y-m-d\TH:i:s\Z', $row['start']), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $row['end']),
            'local_start' => CarbonImmutable::createFromTimestampUTC($row['start'])->setTimezone($owner->timezone)->format('Y-m-d H:i:s'),
            'duration_seconds' => $row['end'] - $row['start']], $rows);
    }
}
