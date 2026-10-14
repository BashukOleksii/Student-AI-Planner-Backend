<?php

namespace App\Services\Planning;

use App\Enums\StudySessionStatus;
use App\Models\StudySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use UnexpectedValueException;

/** SELECT-only adaptive analysis; never retires a source or authorizes later persistence. */
class StudyReplanningPreviewService
{
    public function __construct(
        private PlanningWorkSelectionService $selection,
        private PlanningContextService $context,
        private PlanningScenarioService $scenario,
        private StudyReplanningAnalysisService $analysis,
        private StudySessionLifecycleRules $history,
    ) {}

    public function preview(User $user, string $startDate, string $endDate, mixed $taskId = null, mixed $subtaskId = null): array
    {
        $this->context->validateDates($startDate, $endDate);
        $owner = $user->newQuery()->whereKey($user->getKey())->firstOrFail(['id', 'timezone']);
        $now = CarbonImmutable::now('UTC');
        // Stage A validates strict scope/ownership and uses exactly this supplied clock.
        $selection = $this->selection->select($owner, $taskId, $subtaskId, [], $now);
        $coverage = $this->context->dateIntervals($startDate, $endDate, $owner->timezone);
        $sessions = $coverage === [] ? new Collection : $owner->studySessions()->where('status', StudySessionStatus::Planned)
            ->when($taskId !== null, fn ($query) => $query->where('task_id', $taskId))
            ->when($subtaskId !== null, fn ($query) => $query->where('subtask_id', $subtaskId))
            ->where(function ($query) use ($coverage): void {
                foreach ($coverage as $part) {
                    $query->orWhere(fn ($q) => $q->where('starts_at', '<', $this->context->queryTimestamp($part['end']))
                        ->where('ends_at', '>', $this->context->queryTimestamp($part['start'])));
                }
            })->orderBy('starts_at')->orderBy('id')->get();
        $this->context->validateSessionReferences($owner, $sessions);
        $this->validateHistory($owner, $sessions);
        $tasks = $owner->tasks()->withTrashed()->whereKey($sessions->pluck('task_id')->unique()->all())
            ->with(['subtasks' => fn ($query) => $query->withTrashed()])->get()->keyBy('id');
        $work = [];
        foreach ([...$selection['work_items'], ...$selection['excluded_items']] as $item) {
            $work[$item['task_id'].':'.($item['subtask_id'] ?? 'task')] = $item;
        }
        $sources = [];
        foreach ($sessions as $session) {
            $task = $tasks[$session->task_id];
            $child = $session->subtask_id === null ? null : $task->subtasks->firstWhere('id', $session->subtask_id);
            $unit = $work[$session->task_id.':'.($session->subtask_id ?? 'task')] ?? null;
            $codes = [];
            if ($task->trashed()) {
                $codes[] = 'deleted_task';
            }
            if ($child?->trashed()) {
                $codes[] = 'deleted_subtask';
            }
            $codes = [...$codes, ...array_diff($unit['reason_codes'] ?? [], ['fully_reserved'])];
            if ($codes === [] && $unit === null) {
                throw new UnexpectedValueException('Invalid replanning work source.');
            }
            $sources[] = ['study_session_id' => $session->id, 'task_id' => $session->task_id, 'subtask_id' => $session->subtask_id,
                'original' => ['start' => $this->context->utcSeconds($session->getRawOriginal('starts_at')),
                    'end' => $this->context->utcSeconds($session->getRawOriginal('ends_at'))],
                'effective_deadline_utc' => $unit['effective_deadline_utc'] ?? null, 'priority' => $task->priority->value,
                'effort' => $unit['effort'] ?? null, 'policy_reason_codes' => array_values($codes)];
        }
        // Load shared context by calendar chunks, including full originals crossing requested boundaries.
        // This expands detection only; proposed sessions still use exactly the requested coverage.
        [$first, $last] = $this->analysisDates($sources, $owner->timezone, $startDate, $endDate, $now);
        $snapshots = [];
        $cursor = new DateTimeImmutable($first, new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($last, new DateTimeZone('UTC'));
        while ($cursor <= $end) {
            $until = min($cursor->modify('+30 days'), $end);
            $snapshots[] = $this->context->load($owner, $cursor->format('Y-m-d'), $until->format('Y-m-d'), null, true);
            $cursor = $until->modify('+1 day');
        }
        $snapshot = $this->scenario->combine($snapshots);
        $results = $this->analysis->analyze($sources, $snapshot, $now);
        $ids = array_values(array_column(array_filter($results, fn ($row) => $row['classification'] === 'requires_replanning'), 'study_session_id'));
        $projectedSelection = $ids === [] ? $selection : $this->selection->select($owner, $taskId, $subtaskId, $ids, $now);
        $proposal = $this->analysis->propose($results, $projectedSelection, $snapshot, $coverage, $now);

        return ['as_of_utc' => $now->format('Y-m-d\TH:i:s.u\Z'), 'start_date' => $startDate, 'end_date' => $endDate,
            'timezone' => $owner->timezone, ...$proposal, 'excluded_items' => $selection['excluded_items'], 'warnings' => $selection['warnings']];
    }

    /** Batch ancestor reads grow with history depth, not with independent source count. No locks. */
    private function validateHistory(User $owner, Collection $sources): void
    {
        if ($sources->isEmpty()) {
            return;
        }
        // A planned source having ANY successor is corrupt, including a foreign edge. Fetch no foreign metadata.
        if (StudySession::whereIn('rescheduled_from_session_id', $sources->modelKeys())->exists()) {
            throw new UnexpectedValueException('Invalid replanning history.');
        }
        $records = $sources->keyBy('id');
        $pending = $sources->pluck('rescheduled_from_session_id')->filter()->unique()->all();
        while ($pending !== []) {
            $batch = $owner->studySessions()->whereKey($pending)->get();
            if ($batch->count() !== count($pending)) {
                throw new UnexpectedValueException('Invalid replanning history.');
            }
            $next = [];
            foreach ($batch as $row) {
                $records[$row->id] = $row;
                if ($row->rescheduled_from_session_id !== null && ! $records->has($row->rescheduled_from_session_id)) {
                    $next[] = $row->rescheduled_from_session_id;
                }
            }
            $pending = array_values(array_unique($next));
        }
        foreach ($sources as $source) {
            $component = [];
            $id = $source->id;
            while ($id !== null) {
                if (isset($component[$id])) {
                    throw new UnexpectedValueException('Invalid replanning history.');
                }
                $row = $records[$id];
                $component[$id] = ['user_id' => $row->user_id, 'task_id' => $row->task_id, 'subtask_id' => $row->subtask_id,
                    'rescheduled_from_session_id' => $row->rescheduled_from_session_id, 'status' => $row->status->value];
                $id = $row->rescheduled_from_session_id;
            }
            $this->history->validateChain($component, $source->id);
        }
    }

    private function analysisDates(array $sources, string $timezone, string $first, string $last, DateTimeImmutable $now): array
    {
        $zone = new DateTimeZone($timezone);
        foreach ($sources as $source) {
            if ((new DateTimeImmutable('@'.$source['original']['start'])) <= $now) {
                continue;
            }
            $interval = $source['original'];
            $points = [$interval['start'], $interval['end'] - 1];
            foreach ($zone->getTransitions($interval['start'], $interval['end']) ?: [] as $transition) {
                $points[] = $transition['ts'];
                if ($transition['ts'] > $interval['start']) {
                    $points[] = $transition['ts'] - 1;
                }
            }
            foreach ($points as $point) {
                $label = (new DateTimeImmutable('@'.$point))->setTimezone($zone)->format('Y-m-d');
                $first = min($first, $label);
                $last = max($last, $label);
            }
        }

        return [$first, $last];
    }
}
