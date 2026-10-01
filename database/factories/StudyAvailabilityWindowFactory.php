<?php

namespace Database\Factories;

use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudyAvailabilityWindow> */
class StudyAvailabilityWindowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ];
    }
}
