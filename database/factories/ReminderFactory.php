<?php

namespace Database\Factories;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reminder> */
class ReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'task_id' => null,
            'subtask_id' => null,
            'study_session_id' => null,
            'message' => null,
            'trigger_at' => '2026-10-05 08:00:00',
            'anchor' => null,
            'offset_minutes' => null,
            'status' => ReminderStatus::Scheduled,
            'sent_at' => null,
        ];
    }
}
