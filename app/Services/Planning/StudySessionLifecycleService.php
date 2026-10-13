<?php

namespace App\Services\Planning;

use App\Models\StudySession;
use App\Models\User;
use App\Services\Reminders\ReminderDependencyService;
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
        $this->rules->actualMinutes($actualMinutes);

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
        return $this->mutation->run($user, $id, function ($owner, $source, $task, $child, $interval, $now, $chain) use ($action, $actualMinutes): StudySession {
            $fields = $this->rules->transition($action, $source->status, $interval, $now, $actualMinutes);
            $this->mutation->validateChain($chain, $source->id);
            $reminders = $this->reminders->scheduledForSession($owner, $source);
            if (! $source->update($fields)) {
                throw new LogicException('The session transition could not be saved.');
            }
            $this->reminders->cancel($reminders);

            return $source;
        });
    }
}
