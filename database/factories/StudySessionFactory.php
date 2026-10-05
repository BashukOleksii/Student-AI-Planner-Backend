<?php

namespace Database\Factories;

use App\Enums\StudySessionStatus;
use App\Models\StudySession;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudySession> */
class StudySessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            // Derive the owner from the task, including explicitly supplied tasks.
            'user_id' => fn (array $attributes) => Task::findOrFail($attributes['task_id'])->user_id,
            'subtask_id' => null,
            'rescheduled_from_session_id' => null,
            'starts_at' => '2026-10-05 09:00:00',
            'ends_at' => '2026-10-05 10:00:00',
            'status' => StudySessionStatus::Planned,
            'completed_at' => null,
            'actual_minutes' => null,
        ];
    }
}
