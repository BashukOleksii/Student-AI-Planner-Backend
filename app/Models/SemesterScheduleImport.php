<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'academic_period_id', 'original_filename', 'file_hash', 'parity_anchor_on', 'first_week_parity', 'status', 'template', 'review', 'context_hash', 'preview_token', 'receipt', 'committed_at'])]
#[Hidden(['file_hash', 'context_hash', 'template'])]
class SemesterScheduleImport extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

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

    protected function casts(): array
    {
        return ['template' => 'array', 'review' => 'array', 'receipt' => 'array',
            'parity_anchor_on' => 'immutable_date', 'committed_at' => 'immutable_datetime'];
    }
}
