<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Exceptions\ReminderIntegrityException;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** Invoked only inside the caller's owner/work transaction; never opens a transaction. */
class ReminderSynchronizationService
{
    public function __construct(
        private ReminderRules $rules,
        private ReminderTargetService $targets,
        private ReminderDependencyService $dependencies,
    ) {}

    public function deadlineChanged(User $owner, Task $task, ?Subtask $child = null): void
    {
        $this->run(function () use ($owner, $task, $child): void {
            if (! $this->targets->workOwned($owner, $task, $child)) {
                throw new ReminderIntegrityException;
            }
            $field = $child === null ? 'task_id' : 'subtask_id';
            $reminders = Reminder::where($field, ($child ?? $task)->id)->where('status', ReminderStatus::Scheduled)
                ->orderBy('id')->lockForUpdate()->get();
            $batch = [];
            foreach ($reminders as $reminder) {
                // Include absolute/foreign dependencies in preflight, but only relative rows change.
                $context = $this->preflight($owner, $reminder);
                if ($reminder->anchor !== null) {
                    $changes = $this->targets->eligible($context)
                        ? $this->rules->reconcile($reminder->status, $this->targets->anchor($context), $reminder->offset_minutes, CarbonImmutable::now('UTC'))
                        : ['status' => ReminderStatus::Cancelled, 'sent_at' => null];
                    $batch[] = [$reminder, $changes, $context, $reminder->getRawOriginal('trigger_at')];
                }
            }
            foreach ($batch as [$reminder, $changes]) {
                $this->save($reminder, $changes);
            }
            // A slow batch/event may advance time. Retain the prior committed trigger on cancellation.
            foreach ($batch as [$reminder, , $context, $originalTrigger]) {
                if ($reminder->status === ReminderStatus::Scheduled) {
                    $changes = $this->rules->reconcile($reminder->status, $this->targets->anchor($context), $reminder->offset_minutes, CarbonImmutable::now('UTC'));
                    if (isset($changes['status'])) {
                        $this->save($reminder, [...$changes, 'trigger_at' => $originalTrigger]);
                    }
                }
            }
        });
    }

    public function completed(User $owner, Task $task, ?Subtask $child = null): void
    {
        $this->run(function () use ($owner, $task, $child): void {
            if (! $this->targets->workOwned($owner, $task, $child)) {
                throw new ReminderIntegrityException;
            }
            $children = $child === null ? $task->subtasks()->withTrashed()->orderBy('id')->lockForUpdate()->get()->keyBy('id')
                : collect([$child->id => $child]);
            $childIds = $children->keys()->all();
            // Discover every owner and both attribution directions, but only sessions with live reminders.
            $query = StudySession::where(function ($query) use ($task, $childIds, $child): void {
                $query->whereIn('subtask_id', $childIds);
                if ($child === null) {
                    $query->orWhere('task_id', $task->id);
                }
            })->whereHas('reminders', fn ($query) => $query->where('status', ReminderStatus::Scheduled));
            $sources = $query->get();
            foreach ($sources as $session) {
                $sourceChild = $session->subtask_id === null ? null : $children->get($session->subtask_id);
                if (! $this->targets->attributed($owner, $task, $sourceChild, $session)) {
                    throw new ReminderIntegrityException;
                }
            }
            $sessions = StudySession::whereIn('id', $sources->modelKeys())->orderBy('id')->lockForUpdate()->get();
            $reminders = Reminder::where('status', ReminderStatus::Scheduled)
                ->where(function ($query) use ($task, $childIds, $sessions, $child): void {
                    $query->whereIn('subtask_id', $childIds)->orWhereIn('study_session_id', $sessions->modelKeys());
                    if ($child === null) {
                        $query->orWhere('task_id', $task->id);
                    }
                })->orderBy('id')->lockForUpdate()->get();
            foreach ($reminders as $reminder) {
                $this->preflight($owner, $reminder);
            }
            // Parent completion invalidates every selected target; child completion selects only that unit.
            $this->dependencies->cancel($reminders);
        });
    }

    private function preflight(User $owner, Reminder $reminder): array
    {
        if (! $this->dependencies->compatible($reminder, $owner)) {
            throw new ReminderIntegrityException;
        }

        // Work/session locks are already held; do not acquire earlier locks after Reminder locks.
        return $this->targets->resolve($owner, $reminder->getAttributes(), false, false);
    }

    private function save(Reminder $reminder, array $changes): void
    {
        if (! $reminder->fill($changes)->save()) {
            throw new LogicException('A synchronized reminder could not be saved.');
        }
    }

    private function run(Closure $action): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reminder synchronization requires the owner/work transaction.');
        }
        try {
            $action();
        } catch (Throwable $exception) {
            throw $exception instanceof ReminderIntegrityException ? $exception : new ReminderIntegrityException($exception);
        }
    }
}
