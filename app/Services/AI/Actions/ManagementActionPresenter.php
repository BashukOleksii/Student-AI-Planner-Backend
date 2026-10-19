<?php

namespace App\Services\AI\Actions;

use App\Http\Resources\ReminderResource;
use App\Http\Resources\SubtaskResource;
use App\Models\Reminder;
use App\Models\Subtask;
use App\Models\User;
use App\Services\Reminders\ReminderRules;
use App\Services\Reminders\ReminderTargetService;
use Illuminate\Http\Request;
use UnexpectedValueException;

class ManagementActionPresenter
{
    public function __construct(private ReminderTargetService $targets) {}

    public function subtask(Subtask $subtask): array
    {
        return (new SubtaskResource($subtask))->toArray(new Request);
    }

    public function reminder(User $owner, Reminder $reminder): array
    {
        $result = (new ReminderResource($reminder))->toArray(new Request);
        try {
            if (count((new ReminderRules)->targets($reminder->getAttributes())) > 1) {
                throw new UnexpectedValueException;
            }
            // Historical reads retain stored times; only disclosure of target identity is checked.
            $this->targets->resolve($owner, $reminder->getAttributes(), false, false);
        } catch (UnexpectedValueException) {
            foreach (ReminderRules::TARGETS as $field) {
                $result[$field] = null;
            }
        }

        return $result;
    }
}
