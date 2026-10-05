<?php

namespace Database\Factories;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subject> */
class SubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'education_institution_id' => null,
            'name' => 'Test subject '.fake()->unique()->numerify('########'),
            'code' => null,
            'color' => null,
        ];
    }
}
