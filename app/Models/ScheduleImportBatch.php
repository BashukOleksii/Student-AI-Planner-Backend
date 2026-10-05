<?php

namespace App\Models;

use App\Enums\ScheduleImportBatchStatus;
use Database\Factories\ScheduleImportBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'academic_period_id', 'status', 'original_filename', 'file_hash', 'total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows', 'error_message', 'committed_at'])]
class ScheduleImportBatch extends Model
{
    /** @use HasFactory<ScheduleImportBatchFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ScheduleImportRow::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ScheduleImportBatchStatus::class,
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'committed_at' => 'datetime',
        ];
    }
}
