<?php

namespace Database\Factories;

use App\Models\PlanningPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanningPreference> */
class PlanningPreferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'max_daily_study_minutes' => null,
            'max_weekly_study_minutes' => null,
            'preferred_break_minutes' => null,
            'min_session_minutes' => null,
            'max_session_minutes' => null,
        ];
    }
}
