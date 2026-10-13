<?php

namespace App\Services\Tasks;

use App\Enums\ReminderStatus;
use App\Enums\StudySessionStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderDependencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class TaskDependencyCleanupService
{
    public function __construct(private ReminderDependencyService $reminderDependencies) {}

    /** Called after the deleter locks the owner, owned parent and scoped children. */
    public function cancel(User $user, Task $task, array $subtaskIds, bool $wholeTask = false): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Dependency cleanup requires the deletion transaction.');
        }
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        // Intentionally inspect all owners: filtering unsafe foreign references out would hide blockers.
        $sessions = StudySession::query()->where('status', StudySessionStatus::Planned)->where('starts_at', '>=', $now)
            ->where(function ($query) use ($task, $subtaskIds, $wholeTask): void {
                $query->whereIn('subtask_id', $subtaskIds);
                if ($wholeTask) {
                    $query->orWhere('task_id', $task->getKey());
                }
            })->orderBy('id')->lockForUpdate()->get();
        foreach ($sessions as $session) {
            if ($session->user_id !== $user->id || $session->task_id !== $task->id
                || ($session->subtask_id !== null && ! in_array($session->subtask_id, $subtaskIds, true))) {
                $this->unsafeDependency();
            }
        }
        $sessionIds = $sessions->modelKeys();
        $reminders = Reminder::query()->where('status', ReminderStatus::Scheduled)
            ->where(function ($query) use ($task, $subtaskIds, $sessionIds, $wholeTask): void {
                $query->whereIn('subtask_id', $subtaskIds)->orWhereIn('study_session_id', $sessionIds);
                if ($wholeTask) {
                    $query->orWhere('task_id', $task->getKey());
                }
            })->orderBy('id')->lockForUpdate()->get();
        foreach ($reminders as $reminder) {
            if (! $this->reminderDependencies->compatible($reminder, $user)) {
                $this->unsafeDependency();
            }
        }
        // Complete preflight before the first mutation; the caller's transaction also rolls back write failures.
        foreach ($sessions as $session) {
            $session->update(['status' => StudySessionStatus::Cancelled, 'completed_at' => null, 'actual_minutes' => null]);
        }
        foreach ($reminders as $reminder) {
            $reminder->update(['status' => ReminderStatus::Cancelled, 'sent_at' => null]);
        }
    }

    private function unsafeDependency(): never
    {
        throw ValidationException::withMessages(['deletion' => ['This work cannot be deleted safely because of inconsistent dependencies.']]);
    }
}
