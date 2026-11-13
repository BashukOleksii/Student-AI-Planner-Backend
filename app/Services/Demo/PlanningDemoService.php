<?php

namespace App\Services\Demo;

use App\Models\User;
use App\Services\Planning\PlanningDiagnosticsService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Tasks\SubtaskService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit local fixtures for an existing owner. Never changes settings or existing work. */
class PlanningDemoService
{
    public function __construct(private DemoDatabaseGuard $guard, private TaskService $tasks,
        private SubtaskService $subtasks, private StudyPlanPersistenceService $plans,
        private PlanningDiagnosticsService $diagnostics) {}

    public function resolve(string $selector): User
    {
        $this->guard->inspect(false);
        $query = User::query();
        if (preg_match('/\Aid:([1-9][0-9]*)\z/', $selector, $match)) {
            $query->whereKey($match[1]);
        } else {
            $query->whereRaw('BINARY name = ?', [$selector]);
        }
        $matches = $query->get();
        if ($matches->count() !== 1) {
            throw new RuntimeException('Потрібен рівно один власник із точним ім’ям або явно заданим id:N. Нічого не створено.');
        }

        return $matches->sole();
    }

    public function run(User $user, bool $dryRun): array
    {
        $this->guard->inspect(false);
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Демонстрація потребує власної транзакції.');
        }

        return DB::transaction(function () use ($user, $dryRun): array {
            // The write path starts with the same owner lock used by normal domain writers.
            $owner = User::whereKey($user->id)->when(! $dryRun, fn ($q) => $q->lockForUpdate())->firstOrFail();
            $now = CarbonImmutable::now('UTC');
            $local = $now->setTimezone($owner->timezone);
            $start = $local->toDateString();
            $end = $local->addDays(6)->toDateString();
            $cutoff = $now->getTimestamp() + 900;
            $diagnostics = $this->diagnostics->analyze($owner, $start, $end);
            $first = collect($diagnostics['slots'])->first(fn ($slot) => $slot['can_schedule']);
            $earlyDeadline = CarbonImmutable::createFromTimestampUTC($first['candidate']['start'] ?? $cutoff)->addMinutes(45);
            $definitions = [
                'A' => ['Без дедлайну', 60, 2, null],
                'B1' => ['Високий пріоритет, близький дедлайн', 90, 3, $local->addDays(2)->endOfDay()],
                'B2' => ['Низький пріоритет, пізніший дедлайн', 60, 1, $local->addDays(5)->endOfDay()],
                'C' => ['Завдання з навчальними кроками', null, 2, $local->addDays(5)->endOfDay()],
                'D' => ['Роботи більше за тиждень', 65535, 2, null],
                'E' => ['Замало часу до дедлайну', 65535, 3, $earlyDeadline],
                'F' => ['Завершення з фактичними хвилинами', 60, 2, null],
                'G' => ['Перегляд після зміни доступності', 60, 2, null],
                'H' => ['Поділ початкової сесії 120 хв', 120, 2, null],
            ];
            $result = ['owner_id' => $owner->id, 'owner_name' => $owner->name, 'timezone' => $owner->timezone,
                'start_date' => $start, 'end_date' => $end, 'dry_run' => $dryRun, 'records' => [], 'created_tasks' => 0,
                'created_subtasks' => 0, 'created_sessions' => 0, 'warnings' => $diagnostics['warnings']];
            foreach ($definitions as $key => [$label, $estimate, $priority, $deadline]) {
                $title = '[DEMO-PLANNING] '.$key.' — '.$label;
                $marker = '[DEMO-PLANNING] dataset=v1; key='.$key;
                $matches = $owner->tasks()->withTrashed()->where(fn ($q) => $q->where('title', $title)
                    ->orWhere('description', 'like', $marker."\n%"))->get();
                if ($matches->count() > 1 || ($matches->count() === 1 && ! str_starts_with($matches->sole()->description ?? '', $marker."\n"))) {
                    throw new RuntimeException('Неоднозначний DEMO-маркер '.$key.'. Записи не змінено.');
                }
                $task = $matches->first();
                $created = false;
                if ($task === null && ! $dryRun) {
                    $task = $this->tasks->create($owner, ['title' => $title, 'estimated_minutes' => $estimate, 'priority' => $priority,
                        'deadline_at' => $deadline?->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                        'description' => $marker."\nЛокальний сценарій ручної перевірки. Не видаляйте маркер, якщо потрібна ідемпотентність. Налаштування та реальні записи не змінюються."]);
                    $result['created_tasks']++;
                    $created = true;
                }
                $row = ['key' => $key, 'title' => $task?->title ?? $title, 'task_id' => $task?->id,
                    'state' => $task === null ? 'would_create' : ($created ? 'created' : 'preserved'),
                    'deadline_at' => $task !== null ? $task->getRawOriginal('deadline_at') : $deadline?->setTimezone('UTC')->format('Y-m-d H:i:s'),
                    'subtask_ids' => [], 'sessions' => [], 'preview_summary' => null, 'note' => null];
                if ($task !== null && $key === 'C') {
                    // Only initialize a new parent; never append to or repair a user's modified fixture.
                    if ($created) {
                        foreach ([30, 45, 60] as $index => $minutes) {
                            $this->subtasks->create($owner, $task, ['title' => '[DEMO-PLANNING] C.'.($index + 1).' — Навчальний крок', 'estimated_minutes' => $minutes]);
                            $result['created_subtasks']++;
                        }
                    }
                    $row['subtask_ids'] = $task->subtasks()->pluck('id')->all();
                }
                if ($task !== null && ! $task->trashed()) {
                    if (in_array($key, ['F', 'G', 'H'], true)) {
                        // Preserve all history on repeat, even if the user completed/cancelled a fixture.
                        $existing = $owner->studySessions()->where('task_id', $task->id)->exists();
                        if (! $dryRun && ! $existing) {
                            $desired = $estimate * 60;
                            // Select a later feasible start for this demonstration, without changing
                            // the greedy allocator or any persisted user constraints.
                            $slots = $this->diagnostics->analyze($owner, $start, $end)['slots'];
                            $candidate = collect($slots)->first(fn ($slot) => $slot['can_schedule']
                                && $slot['candidate']['start'] >= $cutoff
                                && $slot['candidate']['end'] - $slot['candidate']['start'] >= $desired);
                            $preview = $this->plans->preview($owner, $start, $end, $task->id, null, $candidate['candidate']['start'] ?? $cutoff);
                            $sessions = $preview['plan']['sessions'];
                            $row['preview_summary'] = $preview['plan']['summary'];
                            $desired = $estimate * 60;
                            if (count($sessions) === 1 && $sessions[0]['duration_seconds'] === $desired) {
                                $saved = $this->plans->confirmWithinTransaction($owner, $preview['preview_token']);
                                $result['created_sessions'] += $saved['sessions']->count();
                            } else {
                                $row['note'] = 'Не створено сесію: чинні умови не дозволяють одну валідну сесію '.$estimate.' хв. Змін не внесено; перевірте preview вручну.';
                            }
                        }
                        $row['sessions'] = $owner->studySessions()->where('task_id', $task->id)->orderBy('id')->get()->map(fn ($s) => [
                            'id' => $s->id, 'status' => $s->status->value, 'starts_at' => $s->starts_at->toIso8601String(),
                            'ends_at' => $s->ends_at->toIso8601String(), 'local_start' => $s->starts_at->copy()->setTimezone($owner->timezone)->format('Y-m-d H:i:s'),
                        ])->all();
                    } else {
                        $row['preview_summary'] = $this->plans->preview($owner, $start, $end, $task->id, null, $cutoff)['plan']['summary'];
                    }
                }
                if ($task?->trashed()) {
                    $row['note'] = 'DEMO-запис свідомо видалено: не відновлено і не створено повторно.';
                }
                if ($dryRun && $task === null && in_array($key, ['F', 'G', 'H'], true)) {
                    $row['note'] = 'Сесію буде створено лише якщо звичайний planner підтвердить одну повну валідну сесію. Dry-run нічого не записує.';
                }
                $result['records'][] = $row;
            }

            return $result;
        });
    }
}
