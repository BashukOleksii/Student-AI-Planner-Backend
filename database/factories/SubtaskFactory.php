<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subtask> */
class SubtaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'title' => 'Test subtask',
            'description' => null,
            'position' => 1,
            'status' => TaskStatus::Pending,
            'estimated_minutes' => null,
            'deadline_at' => null,
            'completed_at' => null,
        ];
    }
}
