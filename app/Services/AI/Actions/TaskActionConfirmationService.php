<?php

namespace App\Services\AI\Actions;

use App\Enums\TaskStatus;
use App\Exceptions\AiAgentException;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskActionConfirmationService
{
    public function __construct(private TaskActionArgumentValidator $validator, private TaskActionPresenter $presenter,
        private TaskActionState $states, private TaskService $tasks) {}

    public function query(User $owner, string $conversation)
    {
        $owner->aiConversations()->whereKey($conversation)->firstOrFail();

        return AiPendingAction::where('conversation_id', $conversation)
            ->select('ai_pending_actions.*')->selectRaw('expires_at <= UTC_TIMESTAMP(6) AS elapsed');
    }

    public function decide(User $owner, string $conversationId, string $actionId, string $operation): array
    {
        // Durability and lock ordering require our own outermost transaction.
        if (DB::transactionLevel() !== 0) {
            throw new AiAgentException('persistence_error');
        }

        return DB::transaction(function () use ($owner, $conversationId, $actionId, $operation): array {
            $conversation = $owner->aiConversations()->whereKey($conversationId)->lockForUpdate()->firstOrFail();
            $action = AiPendingAction::where('conversation_id', $conversationId)->whereKey($actionId)->lockForUpdate()->firstOrFail();
            // No consistent read may establish a REPEATABLE READ snapshot before the owner wait.
            // Reminder synchronization discovers current session dependencies using ordinary reads.
            if ($action->status === 'pending' && $operation === 'confirm') {
                User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            }
            if (! DB::table('ai_conversation_turns')->where('id', $action->turn_id)->where('conversation_id', $conversationId)->whereNotNull('result')->exists()) {
                throw new AiAgentException('persistence_error');
            }
            if ($action->status === 'executed') {
                $this->states->next($action->status, false, $operation);

                return ['action' => $action, 'status' => 200, 'replayed' => true];
            }
            if ($action->status !== 'pending') {
                return $this->outcome($action, $operation);
            }
            // Same lock as H2 claim/finish. No decision/history event may interleave an active turn.
            if (DB::table('ai_conversation_turns')->where('conversation_id', $conversationId)->whereNull('result')
                ->where('lease_expires_at', '>', DB::raw('UTC_TIMESTAMP(6)'))->exists()) {
                throw new AiAgentException('conversation_busy');
            }
            $expired = $this->elapsed($action);
            $next = $this->states->next($action->status, $expired, $operation);
            if ($next === 'executed') {
                $arguments = $this->validator->validate($action->type, $action->arguments);
                $task = isset($arguments['task_id']) ? $owner->tasks()->whereKey($arguments['task_id'])->lockForUpdate()->first() : null;
                if (isset($arguments['task_id']) && ($task === null
                    || ! hash_equals($action->target_hash ?? '', $this->presenter->fingerprint($task)))) {
                    throw new AiAgentException('action_stale');
                }
                try {
                    $values = array_diff_key($arguments, ['task_id' => true]);
                    $result = match ($action->type) {
                        'propose_task_create' => $this->tasks->create($owner, $values),
                        'propose_task_update' => $this->tasks->update($owner, $task, $values),
                        'propose_task_complete' => $this->tasks->complete($owner, $task),
                        'propose_task_reopen' => $this->tasks->reopen($owner, $task),
                        default => throw new AiAgentException('persistence_error'),
                    };
                } catch (ValidationException) {
                    throw new AiAgentException('action_validation_failed');
                }
                if ($action->type === 'propose_task_reopen' && $result->status !== TaskStatus::Pending) {
                    throw new AiAgentException('persistence_error');
                }
                // Any slow dependency lock/event that crosses expiry rolls the entire business mutation back.
                if ($this->elapsed($action)) {
                    throw new AiAgentException('action_expired');
                }
                $subjectId = $result->subject_id === null ? null : $owner->subjects()->whereKey($result->subject_id)->value('id');
                $action->receipt = ['action_id' => $action->id, 'type' => $action->type,
                    'task' => $this->presenter->task($result, $subjectId)];
            }
            $action->status = $next;
            $action->resolved_at = DB::selectOne('SELECT UTC_TIMESTAMP(6) AS instant')->instant;
            if (! $action->save()) {
                throw new AiAgentException('persistence_error');
            }
            $action->refresh();
            $this->record($conversation, $action);

            return $this->outcome($action, $operation);
        });
    }

    private function elapsed(AiPendingAction $action): bool
    {
        return AiPendingAction::whereKey($action->id)->where('expires_at', '<=', DB::raw('UTC_TIMESTAMP(6)'))->exists();
    }

    private function outcome(AiPendingAction $action, string $operation): array
    {
        return ['action' => $action, 'status' => match ($action->status) {
            'expired' => 410, 'rejected' => $operation === 'confirm' ? 409 : 200, default => 200,
        }, 'replayed' => false];
    }

    private function record(AiConversation $conversation, AiPendingAction $action): void
    {
        $event = ['action_id' => $action->id, 'status' => $action->status, 'receipt' => $action->receipt];
        $content = match ($action->status) {
            'executed' => 'Task action executed.', 'rejected' => 'Task action rejected. No task was changed.',
            default => 'Task action expired. No task was changed.',
        };
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => $content,
            'tool_calls' => ['version' => 2, 'action_event' => $event]]);
        if (! $message->exists || ! $conversation->touch()) {
            throw new AiAgentException('persistence_error');
        }
    }
}
