<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicPeriod> */
class AcademicPeriodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'education_institution_id' => null,
            'name' => 'Test period '.fake()->unique()->numerify('########'),
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-31',
        ];
    }
}
