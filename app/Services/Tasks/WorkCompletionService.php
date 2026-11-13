<?php

namespace App\Services\Tasks;

use App\Enums\StudySessionStatus;
use App\Exceptions\ReminderIntegrityException;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderDependencyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Shared reviewed cancellation; callers serialize writes with the owner/work locks. */
class WorkCompletionService
{
    public function __construct(private ReminderDependencyService $reminders) {}

    public function review(User $owner, Task $task, ?Subtask $child = null): array
    {
        $task = $owner->tasks()->findOrFail($task->id);
        if ($child !== null) {
            $child = $task->subtasks()->findOrFail($child->id);
        }
        $now = CarbonImmutable::now('UTC');
        $sessions = $this->sessions($owner, $task, $child, $now, false);

        return $this->overview($owner, $task, $child, $sessions);
    }

    public function cancel(User $owner, Task $task, ?Subtask $child, CarbonImmutable &$now, ?string $token): void
    {
        $sessions = $this->sessions($owner, $task, $child, $now, true);
        $overview = $this->overview($owner, $task, $child, $sessions);
        $expected = null;
        if ($token !== null) {
            try {
                $expected = Crypt::decryptString($token);
            } catch (Throwable) {
                // Invalid consent has the same safe review response as stale consent.
            }
        }
        $material = $this->material($owner, $task, $child, $sessions);
        if (($token !== null && $expected !== $material) || ($token === null && $sessions->isNotEmpty())) {
            throw new HttpResponseException(response()->json([
                'message' => 'Перегляньте наслідки завершення та підтвердьте актуальний набір сесій.',
                'completion_review' => $overview,
            ], 409));
        }
        foreach ($sessions as $session) {
            $reminders = $this->reminders->scheduledForSession($owner, $session);
            if (! $session->update(['status' => StudySessionStatus::Cancelled, 'actual_minutes' => null, 'completed_at' => null])) {
                throw new ReminderIntegrityException;
            }
            $this->reminders->cancel($reminders);
        }
    }

    private function sessions(User $owner, Task $task, ?Subtask $child, CarbonImmutable &$now, bool $lock)
    {
        $children = $task->subtasks()->withTrashed()->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->modelKeys();
        $sessions = StudySession::where(function ($q) use ($task, $child, $children) {
            if ($child !== null) {
                $q->where('subtask_id', $child->id);
            } else {
                $q->where('task_id', $task->id)->orWhereIn('subtask_id', $children);
            }
        })->where('status', StudySessionStatus::Planned)
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($lock) {
            $now = CarbonImmutable::now('UTC');
        }
        $sessions = $sessions->filter(fn ($s) => $s->starts_at->greaterThan($now))->values();
        foreach ($sessions as $session) {
            if ($session->user_id !== $owner->id || $session->task_id !== $task->id
                || ($session->subtask_id !== null && ! in_array($session->subtask_id, $children, true))) {
                throw new ReminderIntegrityException;
            }
        }

        return $sessions;
    }

    private function material(User $owner, Task $task, ?Subtask $child, $sessions): string
    {
        return json_encode([$owner->id, $task->id, $child?->id, $sessions->map(fn ($s) => [$s->id, $s->task_id, $s->subtask_id, $s->getRawOriginal('starts_at'), $s->getRawOriginal('ends_at')])->all()], JSON_THROW_ON_ERROR);
    }

    private function overview(User $owner, Task $task, ?Subtask $child, $sessions): array
    {
        return ['future_sessions_count' => $sessions->count(),
            'completion_token' => Crypt::encryptString($this->material($owner, $task, $child, $sessions)),
            'message' => 'Для цієї роботи заплановано ще '.$sessions->count().' майбутні сесії. Після завершення їх буде скасовано'];
    }
}
