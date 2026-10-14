<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use UnexpectedValueException;

class ReminderDependencyService
{
    /** Shared with work-deletion preflight; target existence is checked by the caller. */
    public function compatible(Reminder $reminder, User $owner): bool
    {
        $targets = array_filter($reminder->only(['task_id', 'subtask_id', 'study_session_id']), fn ($id) => $id !== null);
        if ($reminder->user_id !== $owner->id || count($targets) !== 1) {
            return false;
        }
        $expected = (new ReminderRules)->anchorFor(array_key_first($targets));

        return ($reminder->anchor === null && $reminder->offset_minutes === null)
            || ($reminder->anchor === $expected && is_int($reminder->offset_minutes));
    }

    /** Called only after owner/work/session locks. All preflight precedes cancellation. */
    public function scheduledForSession(User $owner, StudySession $session): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reminder cleanup requires a session transaction.');
        }
        $reminders = Reminder::where('study_session_id', $session->id)->where('status', ReminderStatus::Scheduled)
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($reminders as $reminder) {
            if (! $this->compatible($reminder, $owner)) {
                throw new UnexpectedValueException('Invalid study session reminder relationships.');
            }
        }

        return $reminders;
    }

    public function cancel(Collection $reminders): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reminder cleanup requires a session transaction.');
        }
        foreach ($reminders as $reminder) {
            if (! $reminder->update(['status' => ReminderStatus::Cancelled, 'sent_at' => null])) {
                throw new LogicException('A dependent reminder could not be cancelled.');
            }
        }
    }
}
