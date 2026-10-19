<?php

namespace App\Services\AI\Tools;

use App\Models\User;
use App\Services\AI\Actions\ManagementActionPresenter;
use App\Services\AI\Actions\SubtaskReminderActionRegistry;
use App\Services\Reminders\ReminderService;
use App\Services\Tasks\SubtaskService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

/** H3b's two fixed read adapters. The original H1 Executor and its registry are unchanged. */
class ManagementReadTools
{
    public function __construct(private SubtaskReminderActionRegistry $registry, private ToolArgumentValidator $validator,
        private SubtaskService $subtasks, private ReminderService $reminders, private ManagementActionPresenter $presenter) {}

    public function execute(User $owner, string $name, array $arguments): array
    {
        try {
            $definition = $this->registry->findRead($name);
            if ($definition === null) {
                throw new InvalidArgumentException;
            }
            $this->validator->validate($definition, $arguments);
            if (! $owner->exists || $owner->getKey() === null) {
                return $this->error($name, 'access_denied');
            }
            if ($name === 'list_subtasks_for_task') {
                $task = $owner->tasks()->findOrFail($arguments['task_id']);
                $children = $this->subtasks->all($owner, $task);
                $total = $children->count();
                $data = ['task_id' => $task->id, 'subtasks' => $children->take(100)->map(fn ($child) => $this->presenter->subtask($child))->all(),
                    'meta' => ['total' => $total, 'limit' => 100, 'returned' => min(100, $total), 'truncated' => $total > 100, 'complete' => $total <= 100]];
            } else {
                $page = $this->reminders->listing($owner, $arguments);
                $data = ['reminders' => collect($page->items())->map(fn ($reminder) => $this->presenter->reminder($owner, $reminder))->all(),
                    'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]];
            }

            return ['ok' => true, 'tool' => $name, 'data' => $data];
        } catch (InvalidArgumentException) {
            return $this->error($name, 'invalid_arguments');
        } catch (ModelNotFoundException) {
            return $this->error($name, 'not_found');
        } catch (Throwable) {
            return $this->error($name, 'execution_failed');
        }
    }

    private function error(string $name, string $code): array
    {
        return ['ok' => false, 'tool' => $name, 'error' => ['code' => $code, 'message' => match ($code) {
            'invalid_arguments' => 'Invalid tool arguments.', 'not_found' => 'Resource not found.',
            'access_denied' => 'Access denied.', default => 'Tool execution failed.',
        }]];
    }
}
