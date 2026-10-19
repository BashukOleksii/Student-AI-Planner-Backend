<?php

namespace App\Services\AI\Actions;

use App\Enums\TaskStatus;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** Only prepares an in-memory proposal. Persistence belongs exclusively to successful turn finalization. */
class TaskActionProposalService
{
    public function __construct(private TaskActionArgumentValidator $validator, private TaskService $tasks, private TaskActionPresenter $presenter) {}

    public function prepare(User $owner, string $name, array $arguments, string $callId): array
    {
        try {
            $arguments = $this->validator->validate($name, $arguments);
            if (! $owner->exists || $owner->getKey() === null) {
                return $this->error($name, 'access_denied');
            }

            return DB::transaction(function () use ($owner, $name, $arguments, $callId): array {
                $task = isset($arguments['task_id']) ? $owner->tasks()->findOrFail($arguments['task_id']) : null;
                if (($name === 'propose_task_complete' && $task->status !== TaskStatus::Pending)
                    || ($name === 'propose_task_reopen' && $task->status !== TaskStatus::Completed)) {
                    return $this->error($name, 'invalid_arguments');
                }
                $values = array_diff_key($arguments, ['task_id' => true]);
                // Lifecycle operations preserve unrelated legacy fields, exactly as TaskService does.
                $candidate = in_array($name, ['propose_task_create', 'propose_task_update'], true)
                    ? $this->tasks->prepare($owner, $values, $task) : [];
                $changes = match ($name) {
                    'propose_task_create' => [...$candidate, 'status' => 'pending', 'completed_at' => null],
                    'propose_task_complete' => ['status' => 'completed'],
                    'propose_task_reopen' => ['status' => 'pending', 'completed_at' => null],
                    default => $values,
                };
                $visibleSubjectId = $task?->subject_id;
                if ($visibleSubjectId !== null && ! $owner->subjects()->whereKey($visibleSubjectId)->exists()) {
                    $visibleSubjectId = null;
                }
                $proposal = ['id' => (string) Str::uuid(), 'type' => $name, 'call_id' => $callId, 'arguments' => $arguments,
                    'preview' => $this->presenter->preview($name, $task, $changes, $visibleSubjectId),
                    'target_hash' => $task === null ? null : $this->presenter->fingerprint($task)];

                return ['proposal' => $proposal, 'result' => ['ok' => true, 'tool' => $name, 'data' => [
                    'state' => 'confirmation_required', 'action_id' => $proposal['id'], 'call_id' => $callId, 'preview' => $proposal['preview'],
                ]]];
            });
        } catch (InvalidArgumentException|ValidationException) {
            return $this->error($name, 'invalid_arguments');
        } catch (ModelNotFoundException) {
            return $this->error($name, 'not_found');
        } catch (Throwable) {
            return $this->error($name, 'execution_failed');
        }
    }

    private function error(string $name, string $code): array
    {
        return ['proposal' => null, 'result' => ['ok' => false, 'tool' => $name,
            'error' => ['code' => $code, 'message' => match ($code) {
                'invalid_arguments' => 'Invalid task action arguments.', 'not_found' => 'Resource not found.',
                'access_denied' => 'Access denied.', default => 'Task proposal could not be prepared.',
            }]]];
    }
}
