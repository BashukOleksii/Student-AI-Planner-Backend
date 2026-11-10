<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'semester_schedule_id', 'weekday', 'pair_number', 'pair_label', 'week_pattern', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room', 'source_import_batch_id', 'semester_schedule_import_id', 'import_key', 'import_baseline', 'is_manually_managed'])]
class WeeklyLessonRule extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function semesterSchedule(): BelongsTo
    {
        return $this->belongsTo(SemesterSchedule::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function sourceImportBatch(): BelongsTo
    {
        return $this->belongsTo(ScheduleImportBatch::class, 'source_import_batch_id');
    }

    public function exceptionLessons(): HasMany
    {
        return $this->hasMany(ScheduleDateExceptionLesson::class, 'source_weekly_rule_id');
    }

    public function semesterScheduleImport(): BelongsTo
    {
        return $this->belongsTo(SemesterScheduleImport::class);
    }

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'pair_number' => 'integer',
            'import_baseline' => 'array',
            'is_manually_managed' => 'boolean',
        ];
    }
}
