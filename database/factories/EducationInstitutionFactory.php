<?php

namespace Database\Factories;

use App\Models\EducationInstitution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EducationInstitution> */
class EducationInstitutionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Test institution '.fake()->unique()->numerify('########'),
        ];
    }
}
