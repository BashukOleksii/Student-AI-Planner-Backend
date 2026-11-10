<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'academic_period_id', 'group_name', 'parity_anchor_on', 'first_week_parity'])]
class SemesterSchedule extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    public function weeklyLessonRules(): HasMany
    {
        return $this->hasMany(WeeklyLessonRule::class);
    }

    public function dateExceptions(): HasMany
    {
        return $this->hasMany(ScheduleDateException::class);
    }

    protected function casts(): array
    {
        return [
            'parity_anchor_on' => 'immutable_date',
        ];
    }
}
