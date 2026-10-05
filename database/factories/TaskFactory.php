<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject_id' => null,
            'title' => 'Test task',
            'description' => null,
            'status' => TaskStatus::Pending,
            'priority' => TaskPriority::Normal,
            'estimated_minutes' => null,
            'deadline_at' => null,
            'completed_at' => null,
        ];
    }
}
