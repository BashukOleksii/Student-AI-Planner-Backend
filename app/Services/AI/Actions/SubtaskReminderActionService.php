<?php

namespace App\Services\AI\Actions;

use App\Enums\TaskStatus;
use App\Exceptions\AiAgentException;
use App\Exceptions\ReminderConflictException;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Reminders\ReminderMutationService;
use App\Services\Reminders\ReminderRules;
use App\Services\Reminders\ReminderTargetService;
use App\Services\Tasks\SubtaskService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/** Seven fixed domain adapters. All state/receipt/lease decisions remain in H3a's confirmation service. */
class SubtaskReminderActionService
{
    public function __construct(private SubtaskReminderArgumentValidator $validator, private SubtaskService $subtasks,
        private ReminderMutationService $reminders, private ReminderRules $rules, private ReminderTargetService $targets,
        private ManagementActionPresenter $presenter, private TaskActionPresenter $values, private ActionTargetFingerprint $fingerprints) {}

    public function prepare(User $owner, string $name, array $arguments, string $callId): array
    {
        try {
            $arguments = $this->validator->validate($name, $arguments);
            if (! $owner->exists || $owner->getKey() === null) {
                return $this->error($name, 'access_denied');
            }

            return DB::transaction(function () use ($owner, $name, $arguments, $callId): array {
                $records = $this->load($owner, $name, $arguments, false);
                $preview = $this->preview($owner, $name, $arguments, $records);
                $proposal = ['id' => (string) Str::uuid(), 'type' => $name, 'call_id' => $callId, 'arguments' => $arguments,
                    'preview' => $preview, 'target_hash' => $this->fingerprints->hash($records)];

                return ['proposal' => $proposal, 'result' => ['ok' => true, 'tool' => $name, 'data' => [
                    'state' => 'confirmation_required', 'action_id' => $proposal['id'], 'call_id' => $callId, 'preview' => $preview,
                ]]];
            });
        } catch (ModelNotFoundException) {
            return $this->error($name, 'not_found');
        } catch (InvalidArgumentException|ValidationException|ReminderConflictException|UnexpectedValueException) {
            return $this->error($name, 'invalid_arguments');
        } catch (Throwable) {
            return $this->error($name, 'execution_failed');
        }
    }

    /** Caller already holds conversation/action/owner, before its first consistent read. Never commits independently. */
    public function execute(User $owner, AiPendingAction $action): array
    {
        if (DB::transactionLevel() === 0) {
            throw new AiAgentException('persistence_error');
        }
        $name = $action->type;
        $arguments = $this->validator->validate($name, $action->arguments);
        try {
            $records = $this->load($owner, $name, $arguments, true);
        } catch (ModelNotFoundException|UnexpectedValueException) {
            throw new AiAgentException('action_stale');
        }
        if (! hash_equals($action->target_hash ?? '', $this->fingerprints->hash($records))) {
            throw new AiAgentException('action_stale');
        }
        try {
            // Revalidate current eligibility and the effective trigger using shared domain preparation.
            $this->preview($owner, $name, $arguments, $records);
            if (str_starts_with($name, 'propose_subtask_')) {
                $task = $records['task'];
                $child = $records['child'];
                $data = array_diff_key($arguments, ['task_id' => true, 'subtask_id' => true]);
                $result = match ($name) {
                    'propose_subtask_create' => $this->subtasks->create($owner, $task, $data),
                    'propose_subtask_update' => $this->subtasks->update($owner, $task, $child, $data),
                    'propose_subtask_complete' => $this->subtasks->complete($owner, $task, $child),
                    'propose_subtask_reopen' => $this->subtasks->reopen($owner, $task, $child),
                };
                if ($name === 'propose_subtask_reopen' && $result->status !== TaskStatus::Pending) {
                    throw new AiAgentException('persistence_error');
                }

                return ['subtask' => $this->presenter->subtask($result)];
            }
            $data = array_diff_key($arguments, ['reminder_id' => true]);
            $result = match ($name) {
                'propose_reminder_create' => $this->reminders->create($owner, $data),
                'propose_reminder_update' => $this->reminders->update($owner, (string) $arguments['reminder_id'], $data),
                'propose_reminder_cancel' => $this->reminders->cancel($owner, (string) $arguments['reminder_id']),
            };

            return ['reminder' => $this->presenter->reminder($owner, $result)];
        } catch (ValidationException|ReminderConflictException|InvalidArgumentException) {
            throw new AiAgentException('action_validation_failed');
        }
    }

    private function load(User $owner, string $name, array $arguments, bool $lock): array
    {
        if (str_starts_with($name, 'propose_subtask_')) {
            $task = $owner->tasks()->whereKey($arguments['task_id'])->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
            $child = isset($arguments['subtask_id']) ? $task->subtasks()->whereKey($arguments['subtask_id'])
                ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail() : null;

            return compact('task', 'child');
        }
        $reminder = isset($arguments['reminder_id']) ? $owner->reminders()->findOrFail($arguments['reminder_id']) : null;
        $context = $reminder === null ? $this->targets->resolve($owner, $arguments, $lock, false)
            : $this->targets->forReminder($owner, $reminder, $lock);
        if ($lock && $reminder !== null) {
            $reminder = $owner->reminders()->whereKey($reminder->id)->lockForUpdate()->firstOrFail();
        }

        return [...$context, 'reminder' => $reminder];
    }

    private function preview(User $owner, string $name, array $arguments, array $records): array
    {
        if (str_starts_with($name, 'propose_subtask_')) {
            ['task' => $task, 'child' => $child] = $records;
            if (($name === 'propose_subtask_complete' && $child->status !== TaskStatus::Pending)
                || ($name === 'propose_subtask_reopen' && $child->status !== TaskStatus::Completed)) {
                throw new InvalidArgumentException;
            }
            $data = array_diff_key($arguments, ['task_id' => true, 'subtask_id' => true]);
            $candidate = in_array($name, ['propose_subtask_create', 'propose_subtask_update'], true)
                ? $this->subtasks->prepare($task, $data, $child) : [];
            $changes = match ($name) {
                'propose_subtask_create' => [...$candidate, 'status' => 'pending', 'completed_at' => null],
                'propose_subtask_complete' => ['status' => 'completed'],
                'propose_subtask_reopen' => ['status' => 'pending', 'completed_at' => null],
                default => $data,
            };

            return ['action' => $name, 'confirmation_required' => true, 'parent' => [
                'id' => $task->id, 'title' => $task->title, 'status' => $task->status->value,
                'deadline_at' => $this->values->task($task, null)['deadline_at'],
            ], 'before' => $child === null ? null : $this->presenter->subtask($child),
                'changes' => $this->values->values($changes), 'effects' => [match ($name) {
                    'propose_subtask_create' => 'Append one pending subtask. The service assigns its position at confirmation.',
                    'propose_subtask_complete' => 'Complete only this subtask and cancel its dependent scheduled reminders. Parent and study-session statuses are preserved.',
                    'propose_subtask_reopen' => 'Reopen only this subtask. Cancelled reminders and retired study sessions are not recreated.',
                    default => 'Update the supplied fields. Deadline changes reconcile existing relative reminders.',
                }]];
        }
        $reminder = $records['reminder'];
        $data = array_diff_key($arguments, ['reminder_id' => true]);
        $before = $reminder === null ? null : $this->presenter->reminder($owner, $reminder);
        $changes = $data;
        if ($name === 'propose_reminder_create') {
            ['trigger' => $trigger] = $this->reminders->prepareCreate($owner, $data);
            $changes = ['message' => null, 'task_id' => null, 'subtask_id' => null, 'study_session_id' => null,
                'anchor' => null, 'offset_minutes' => null, ...$data, 'trigger_at' => $trigger, 'status' => 'scheduled', 'sent_at' => null];
        } elseif ($name === 'propose_reminder_update') {
            $changes['trigger_at'] = $this->reminders->prepareUpdate($reminder, $data, $records);
        } else {
            $this->rules->editable($reminder->status);
            $changes = ['status' => 'cancelled'];
        }

        return ['action' => $name, 'confirmation_required' => true, 'mode' => ($data['anchor'] ?? $reminder?->anchor) === null ? 'absolute' : 'relative',
            'before' => $before, 'changes' => $changes, 'effects' => [$name === 'propose_reminder_cancel'
                ? 'Cancel this scheduled reminder. Preserve its trigger, mode, work, sessions and delivered inbox history.'
                : 'Schedule the reviewed reminder change. Delivery is a separate subsystem; no inbox notification is sent now.']];
    }

    private function error(string $name, string $code): array
    {
        return ['proposal' => null, 'result' => ['ok' => false, 'tool' => $name, 'error' => ['code' => $code, 'message' => match ($code) {
            'not_found' => 'Resource not found.', 'access_denied' => 'Access denied.',
            'invalid_arguments' => 'Invalid action arguments.', default => 'Action proposal could not be prepared.',
        }]]];
    }
}
