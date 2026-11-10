<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'semester_schedule_id', 'weekday', 'pair_number', 'week_pattern'])]
class SemesterScheduleSuppressedSlot extends Model
{
    public function semesterSchedule(): BelongsTo
    {
        return $this->belongsTo(SemesterSchedule::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'pair_number' => 'integer'];
    }
}
