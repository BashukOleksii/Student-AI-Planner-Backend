<?php

namespace App\Services\AI\Tools;

use App\Http\Resources\FreeSlotResource;
use App\Http\Resources\StudyAvailabilityWindowResource;
use App\Http\Resources\StudySessionResource;
use App\Models\User;
use App\Services\Planning\FreeTimeService;
use App\Services\Planning\StudySessionReadService;
use App\Services\Progress\ProgressComparisonService;
use App\Services\Progress\ProgressStatisticsService;
use App\Services\Progress\ProgressTrendsService;
use App\Services\Schedule\ScheduleService;
use App\Services\Tasks\TaskService;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Eight thin read adapters. The match is the complete, server-controlled dispatch allowlist. */
class ReadOnlyTools
{
    public function __construct(
        private ScheduleService $schedule,
        private TaskService $tasks,
        private FreeTimeService $freeTime,
        private StudySessionReadService $sessions,
        private ProgressStatisticsService $statistics,
        private ProgressTrendsService $trends,
        private ProgressComparisonService $comparison,
        private ToolResultPresenter $presenter,
    ) {}

    public function execute(User $owner, string $name, array $arguments): array
    {
        // Schedule/PF1 use the supplied profile directly. Reject stale/unsaved timezone overrides.
        $owner = $owner->newQuery()->whereKey($owner->getKey())->firstOrFail(['id', 'timezone']);

        return match ($name) {
            'get_schedule_for_date' => $this->schedule($owner, $arguments),
            'list_tasks' => $this->tasks($owner, $arguments),
            'list_study_availability_windows' => $this->availabilityWindows($owner, $arguments),
            'find_free_study_slots' => $this->freeSlots($owner, $arguments),
            'list_study_sessions' => $this->sessions($owner, $arguments),
            'get_progress_statistics' => $this->statistics->calculate($owner, $arguments['start_date'], $arguments['end_date']),
            'get_daily_progress_trends' => $this->trends->calculate($owner, $arguments['start_date'], $arguments['end_date']),
            'compare_progress_periods' => $this->comparison->calculate($owner, $arguments['baseline_start_date'], $arguments['baseline_end_date'],
                $arguments['current_start_date'], $arguments['current_end_date']),
            default => throw new LogicException('Unregistered tool adapter.'),
        };
    }

    private function schedule(User $owner, array $arguments): array
    {
        $result = $this->schedule->date($owner, $arguments['date']);

        return ['lessons' => $result['lessons']->map(fn ($lesson) => $this->presenter->lesson($owner, $lesson))->all(), 'meta' => $result['meta']];
    }

    private function tasks(User $owner, array $arguments): array
    {
        $page = $this->tasks->paginate($owner, $arguments);

        return ['tasks' => $page->getCollection()->map(fn ($task) => $this->presenter->task($owner, $task))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]];
    }

    private function availabilityWindows(User $owner, array $arguments): array
    {
        $page = $arguments['page'] ?? 1;
        $perPage = $arguments['per_page'] ?? 20;
        if ($page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw ValidationException::withMessages(['page' => ['The selected page is too large.']]);
        }

        $windows = $owner->studyAvailabilityWindows()
            ->when(isset($arguments['day_of_week']), fn ($query) => $query->where('day_of_week', $arguments['day_of_week']))
            ->orderBy('day_of_week')->orderBy('starts_at')->orderBy('id')
            ->paginate($perPage, ['id', 'day_of_week', 'starts_at', 'ends_at'], 'page', $page);

        // This Resource uses no authentication or Request state; preserve local times including 24:00:00.
        return ['windows' => $windows->getCollection()->map(fn ($window) => (new StudyAvailabilityWindowResource($window))->resolve())->all(),
            'meta' => ['timezone' => $owner->timezone, 'current_page' => $windows->currentPage(),
                'per_page' => $windows->perPage(), 'total' => $windows->total(), 'last_page' => $windows->lastPage()]];
    }

    private function freeSlots(User $owner, array $arguments): array
    {
        $minimum = isset($arguments['min_slot_minutes']) ? $arguments['min_slot_minutes'] * 60 : null;
        $slots = $this->freeTime->calculate($owner, $arguments['start_date'], $arguments['end_date'], $minimum);

        // These two Resources use no authentication, query parameters or other Request state.
        return ['slots' => array_map(fn ($slot) => (new FreeSlotResource($slot))->resolve(), $slots),
            'meta' => ['timezone' => $owner->timezone, 'start_date' => $arguments['start_date'], 'end_date' => $arguments['end_date']]];
    }

    private function sessions(User $owner, array $arguments): array
    {
        $result = $this->sessions->range($owner, $arguments['start_date'], $arguments['end_date']);
        // Validate the complete history in the existing Service before bounding presentation.
        $total = $result['sessions']->count();
        $sessions = $result['sessions']->take(100)->map(fn ($session) => (new StudySessionResource($session))->resolve())->values()->all();

        return ['sessions' => $sessions, 'meta' => [...$result['meta'], 'limit' => 100, 'returned' => count($sessions),
            'total' => $total, 'truncated' => $total > count($sessions)]];
    }
}
