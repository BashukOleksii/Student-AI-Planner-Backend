<?php

namespace App\Services\Planning;

use App\Enums\TaskStatus;
use App\Models\StudySession;
use App\Models\User;
use App\Services\Reminders\ReminderDependencyService;
use App\Services\Tasks\SubtaskService;
use App\Services\Tasks\TaskService;
use App\Services\Tasks\WorkCompletionService;
use Closure;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class StudySessionLifecycleService
{
    public function __construct(
        private StudySessionMutationContextService $mutation,
        private StudySessionLifecycleRules $rules,
        private ReminderDependencyService $reminders,
    ) {}

    public function complete(User $user, string $id, mixed $actualMinutes = null, bool $completeWork = false, ?string $completionToken = null): StudySession
    {
        $this->rules->actualMinutes($actualMinutes, true);

        return $this->mutation->run($user, $id, $this->action('complete', $actualMinutes, null, $completeWork, $completionToken));
    }

    /** Read-only capability and consequence review; work identity comes exclusively from the session. */
    public function completionReview(User $user, string $id): array
    {
        return $this->mutation->read($user, $id, function ($owner, $source, $task, $child): array {
            $available = $this->canCompleteWork($task, $child);

            return ['can_complete_work' => $available, 'work_type' => $child === null ? 'task' : 'subtask',
                'completion_review' => $available
                    ? app(WorkCompletionService::class)->review($owner, $task, $child) : null];
        });
    }

    private function canCompleteWork($task, $child): bool
    {
        return ! $task->trashed() && ($child === null ? $task->status->isUnfinished()
            : (! $child->trashed() && $child->status === TaskStatus::Pending));
    }

    public function miss(User $user, string $id): StudySession
    {
        return $this->transition($user, $id, 'miss');
    }

    public function cancel(User $user, string $id): StudySession
    {
        return $this->transition($user, $id, 'cancel');
    }

    private function transition(User $user, string $id, string $action, mixed $actualMinutes = null): StudySession
    {
        return $this->mutation->run($user, $id, $this->action($action, $actualMinutes));
    }

    /** AI confirmation entry point; requires the existing owner-first transaction and lock order. */
    public function completeWithinTransaction(User $user, string $id, mixed $actualMinutes, Closure $beforeTransition): StudySession
    {
        $this->rules->actualMinutes($actualMinutes, true);

        return $this->mutation->runWithinTransaction($user, $id, $this->action('complete', $actualMinutes, $beforeTransition));
    }

    public function missWithinTransaction(User $user, string $id, Closure $beforeTransition): StudySession
    {
        return $this->mutation->runWithinTransaction($user, $id, $this->action('miss', null, $beforeTransition));
    }

    private function action(string $action, mixed $actualMinutes, ?Closure $beforeTransition = null, bool $completeWork = false, ?string $completionToken = null): Closure
    {
        return function ($owner, $source, $task, $child, $interval, $now, $chain) use ($action, $actualMinutes, $beforeTransition, $completeWork, $completionToken): StudySession {
            $fields = $this->rules->transition($action, $source->status, $interval, $now, $actualMinutes);
            $this->mutation->validateChain($chain, $source->id);
            if ($completeWork) {
                if (! $this->canCompleteWork($task, $child)) {
                    throw new ConflictHttpException('Linked work can no longer be completed. Review the session and complete it separately.');
                }
                if ($child === null) {
                    app(TaskService::class)->complete($owner, $task, $completionToken, $now);
                } else {
                    app(SubtaskService::class)->complete($owner, $task, $child, $completionToken, $now);
                }
            }
            $fields = $this->rules->transition($action, $source->status, $interval, $now, $actualMinutes);
            $reminders = $this->reminders->scheduledForSession($owner, $source);
            $beforeTransition?->__invoke($owner, $source, $task, $child, $interval, $now, $chain, $reminders);
            if (! $source->update($fields)) {
                throw new LogicException('The session transition could not be saved.');
            }
            $this->reminders->cancel($reminders);

            return $source;
        };
    }
}
