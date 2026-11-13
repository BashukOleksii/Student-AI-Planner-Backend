<?php

namespace App\Services\Planning;

use App\Enums\TaskStatus;
use App\Models\User;

class PlanningWorkOptionsService
{
    public function __construct(private PlanningWorkSelectionService $selection) {}

    public function search(User $owner, array $values): array
    {
        $taskId = isset($values['task_id']) ? (int) $values['task_id'] : null;
        $search = $values['search'] ?? '';
        $page = (int) ($values['page'] ?? 1);
        $selection = $this->selection->select($owner, $taskId, isset($values['subtask_id']) ? (int) $values['subtask_id'] : null);
        $units = collect($selection['work_items']);
        if (($values['mode'] ?? 'planning') === 'replanning') {
            // A fully reserved/expired unit may still need review of its existing plan.
            $units = $units->merge($selection['excluded_items']);
        }
        $ids = $units->pluck($taskId === null ? 'task_id' : 'subtask_id')->filter()->unique();
        $meta = [];
        if ($taskId === null) {
            $query = $owner->tasks()->whereIn('id', $ids)->where('status', '!=', TaskStatus::Completed);
        } else {
            $task = $owner->tasks()->findOrFail($taskId);
            $children = $task->subtasks()->get(['id', 'status']);
            $meta = ['has_subtasks' => $children->isNotEmpty(),
                'all_subtasks_completed' => $children->isNotEmpty() && $children->every(fn ($c) => $c->status === TaskStatus::Completed),
                'task_available' => $task->status !== TaskStatus::Completed && $units->isNotEmpty()];
            $query = $task->subtasks()->whereIn('id', $ids)->where('status', TaskStatus::Pending);
            if ($task->status === TaskStatus::Completed) {
                $query->whereRaw('1 = 0');
            }
        }
        // Escape LIKE metacharacters: search terms are literal, not patterns.
        $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
        $result = $query->when($search !== '', fn ($q) => $q->whereRaw("title LIKE ? ESCAPE '!'", ['%'.$literal.'%']))
            ->orderBy('title')->orderBy('id')->paginate(20, ['id', 'title'], 'page', $page);

        return ['data' => $result->items(), 'meta' => [...$meta, 'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage(), 'total' => $result->total()]];
    }
}
