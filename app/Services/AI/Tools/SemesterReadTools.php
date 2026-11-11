<?php

namespace App\Services\AI\Tools;

use App\Http\Resources\SemesterScheduleLessonDefinitionResource;
use App\Http\Resources\SemesterScheduleResource;
use App\Http\Resources\SemesterScheduleSuppressedSlotResource;
use App\Models\User;
use App\Services\AI\Actions\SemesterActionArgumentValidator;
use App\Services\Schedule\EffectiveScheduleService;
use App\Services\Schedule\SemesterScheduleManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

class SemesterReadTools
{
    public function __construct(private SemesterActionArgumentValidator $validator,
        private SemesterScheduleManagementService $management, private EffectiveScheduleService $effective) {}

    public function execute(User $user, string $name, array $arguments): array
    {
        try {
            $arguments = $this->validator->validate($name, $arguments, true);
            if (! $user->exists || $user->getKey() === null) {
                return $this->error($name, 'access_denied');
            }
            $data = DB::transaction(function () use ($user, $name, $arguments): array {
                $owner = User::findOrFail($user->id);
                $page = $arguments['page'] ?? 1;
                $limit = $arguments['per_page'] ?? 20;
                if ($name === 'search_academic_catalog') {
                    $query = $arguments['kind'] === 'subject' ? $owner->subjects()->with('teachers') : $owner->teachers();
                    if (isset($arguments['query'])) {
                        $query->where('name', 'like', '%'.addcslashes($arguments['query'], '%_\\').'%');
                    }
                    $records = $query->orderBy('name')->orderBy('id')->paginate($limit, ['id', 'name'], 'page', $page);

                    return ['kind' => $arguments['kind'], 'items' => array_map(function ($row) use ($owner, $arguments): array {
                        $item = $row->only(['id', 'name']);
                        if ($arguments['kind'] === 'subject') {
                            if ($row->teachers->count() > 1 || $row->teachers->contains(fn ($teacher) => $teacher->user_id !== $owner->id || $teacher->pivot->user_id !== $owner->id)) {
                                throw new \UnexpectedValueException;
                            }
                            $item['teachers'] = $row->teachers->map(fn ($teacher) => $teacher->only(['id', 'name']))->values()->all();
                        }

                        return $item;
                    }, $records->items()),
                        'meta' => $this->meta($records->total(), $page, $limit, count($records->items()))];
                }
                if ($name === 'list_semester_schedules') {
                    $schedules = $this->effective->schedules($owner);
                    $rows = $schedules->slice(($page - 1) * $limit, $limit)->values();

                    return ['schedules' => $rows->map(fn ($schedule) => (new SemesterScheduleResource($schedule))->resolve())->all(),
                        'meta' => $this->meta($schedules->count(), $page, $limit, $rows->count())];
                }
                $schedule = $owner->semesterSchedules()->findOrFail($arguments['semester_schedule_id']);
                Gate::forUser($owner)->authorize('view', $schedule);
                $graph = $this->management->read($owner, $schedule);
                $rows = $graph['rules']->slice(($page - 1) * $limit, $limit)->values();
                $suppressions = $graph['suppressions']->slice(($page - 1) * $limit, $limit)->values();
                $exception = isset($arguments['date']) ? $graph['exceptions']->first(fn ($row) => $row->occurs_on->format('Y-m-d') === $arguments['date']) : null;
                $snapshot = null;
                if ($exception !== null) {
                    $all = $exception->lessons;
                    $lessons = $all->slice(($page - 1) * $limit, $limit)->values();
                    $snapshot = ['id' => $exception->id, 'occurs_on' => $exception->occurs_on->format('Y-m-d'), 'kind' => $exception->kind,
                        'copied_weekday' => $exception->copied_weekday, 'source_week_parity' => $exception->source_week_parity,
                        'lessons' => $lessons->map(fn ($row) => (new SemesterScheduleLessonDefinitionResource($row))->resolve())->all(),
                        'meta' => $this->meta($all->count(), $page, $limit, $lessons->count())];
                }

                return ['schedule' => (new SemesterScheduleResource($graph['schedule']))->resolve(),
                    'timezone' => $owner->timezone,
                    'weekly_rules' => $rows->map(fn ($row) => (new SemesterScheduleLessonDefinitionResource($row))->resolve())->all(),
                    'meta' => $this->meta($graph['rules']->count(), $page, $limit, $rows->count()),
                    'suppressed_slots' => $suppressions->map(fn ($row) => (new SemesterScheduleSuppressedSlotResource($row))->resolve())->all(),
                    'suppressed_slots_meta' => $this->meta($graph['suppressions']->count(), $page, $limit, $suppressions->count()),
                    'requested_date' => $arguments['date'] ?? null, 'date_exception' => $snapshot];
            });

            return ['ok' => true, 'tool' => $name, 'data' => $data];
        } catch (InvalidArgumentException) {
            return $this->error($name, 'invalid_arguments');
        } catch (ModelNotFoundException|AuthorizationException) {
            return $this->error($name, 'not_found');
        } catch (Throwable) {
            return $this->error($name, 'execution_failed');
        }
    }

    private function meta(int $total, int $page, int $limit, int $returned): array
    {
        return ['current_page' => $page, 'per_page' => $limit, 'total' => $total, 'returned' => $returned,
            'last_page' => max(1, (int) ceil($total / $limit)), 'truncated' => $returned < $total];
    }

    private function error(string $name, string $code): array
    {
        return ['ok' => false, 'tool' => $name, 'error' => ['code' => $code, 'message' => match ($code) {
            'invalid_arguments' => 'Invalid tool arguments.', 'not_found' => 'Resource not found.',
            'access_denied' => 'Access denied.', default => 'Tool execution failed.',
        }]];
    }
}
