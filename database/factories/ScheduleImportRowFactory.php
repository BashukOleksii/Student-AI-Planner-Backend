<?php

namespace Database\Factories;

use App\Enums\ScheduleImportRowStatus;
use App\Models\ScheduleImportBatch;
use App\Models\ScheduleImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduleImportRow> */
class ScheduleImportRowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'schedule_import_batch_id' => ScheduleImportBatch::factory(),
            'row_number' => fake()->unique()->numberBetween(1, 1000000),
            'status' => ScheduleImportRowStatus::Valid,
            'raw_data' => ['cells' => ['Test subject', 'Test teacher']],
            'normalized_data' => null,
            'validation_errors' => null,
            'fingerprint' => null,
        ];
    }
}
