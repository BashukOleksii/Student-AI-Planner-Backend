<?php

namespace App\Services\Planning;

use App\Models\StudySession;
use App\Models\User;
use App\Services\Reminders\ReminderDependencyService;
use Closure;
use LogicException;

class StudySessionLifecycleService
{
    public function __construct(
        private StudySessionMutationContextService $mutation,
        private StudySessionLifecycleRules $rules,
        private ReminderDependencyService $reminders,
    ) {}

    public function complete(User $user, string $id, mixed $actualMinutes = null): StudySession
    {
        $this->rules->actualMinutes($actualMinutes, true);

        return $this->transition($user, $id, 'complete', $actualMinutes);
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

    private function action(string $action, mixed $actualMinutes, ?Closure $beforeTransition = null): Closure
    {
        return function ($owner, $source, $task, $child, $interval, $now, $chain) use ($action, $actualMinutes, $beforeTransition): StudySession {
            $fields = $this->rules->transition($action, $source->status, $interval, $now, $actualMinutes);
            $this->mutation->validateChain($chain, $source->id);
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
