<?php

namespace Database\Factories;

use App\Enums\ScheduleImportBatchStatus;
use App\Models\AcademicPeriod;
use App\Models\ScheduleImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduleImportBatch> */
class ScheduleImportBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'academic_period_id' => AcademicPeriod::factory(),
            // Reuse the period's owner, including when an existing period is supplied.
            'user_id' => fn (array $attributes) => AcademicPeriod::findOrFail($attributes['academic_period_id'])->user_id,
            'status' => ScheduleImportBatchStatus::Uploaded,
            'original_filename' => 'test-schedule.xlsx',
            'file_hash' => fake()->regexify('[0-9a-f]{64}'),
            'error_message' => null,
            'committed_at' => null,
        ];
    }
}
