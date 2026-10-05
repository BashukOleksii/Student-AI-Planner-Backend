<?php

namespace App\Models;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'academic_period_id', 'subject_id', 'teacher_id', 'schedule_import_batch_id', 'replaces_lesson_id', 'type', 'room', 'starts_at', 'ends_at', 'status', 'import_fingerprint'])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory, SoftDeletes;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function scheduleImportBatch(): BelongsTo
    {
        return $this->belongsTo(ScheduleImportBatch::class);
    }

    public function replacedLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'replaces_lesson_id');
    }

    public function replacement(): HasOne
    {
        return $this->hasOne(Lesson::class, 'replaces_lesson_id');
    }

    protected function casts(): array
    {
        return [
            'type' => LessonType::class,
            'status' => LessonStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
