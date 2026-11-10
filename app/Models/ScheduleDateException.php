<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'semester_schedule_id', 'occurs_on', 'kind', 'copied_weekday'])]
class ScheduleDateException extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function semesterSchedule(): BelongsTo
    {
        return $this->belongsTo(SemesterSchedule::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(ScheduleDateExceptionLesson::class);
    }

    protected function casts(): array
    {
        return [
            'occurs_on' => 'immutable_date',
            'copied_weekday' => 'integer',
        ];
    }
}
