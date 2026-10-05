<?php

namespace App\Models;

use App\Enums\ScheduleImportRowStatus;
use Database\Factories\ScheduleImportRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['schedule_import_batch_id', 'row_number', 'status', 'raw_data', 'normalized_data', 'validation_errors', 'fingerprint'])]
class ScheduleImportRow extends Model
{
    /** @use HasFactory<ScheduleImportRowFactory> */
    use HasFactory;

    public function scheduleImportBatch(): BelongsTo
    {
        return $this->belongsTo(ScheduleImportBatch::class);
    }

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'status' => ScheduleImportRowStatus::class,
            'raw_data' => 'array',
            'normalized_data' => 'array',
            'validation_errors' => 'array',
        ];
    }
}
