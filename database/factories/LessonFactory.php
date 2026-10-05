<?php

namespace Database\Factories;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lesson> */
class LessonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject_id' => fn (array $attributes) => Subject::factory()->create(['user_id' => $attributes['user_id']])->id,
            'academic_period_id' => null,
            'teacher_id' => null,
            'schedule_import_batch_id' => null,
            'replaces_lesson_id' => null,
            'type' => LessonType::Lecture,
            'room' => null,
            // Whole-second UTC fixtures; product scheduling defaults are not defined here.
            'starts_at' => '2026-10-05 06:00:00',
            'ends_at' => '2026-10-05 07:30:00',
            'status' => LessonStatus::Active,
            'import_fingerprint' => null,
        ];
    }
}
