<?php

namespace App\Models;

use App\Models\Concerns\HasSemesterTeachers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['user_id', 'semester_schedule_id', 'schedule_date_exception_id', 'pair_number', 'pair_label', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room', 'source_weekly_rule_id'])]
class ScheduleDateExceptionLesson extends Model
{
    use HasSemesterTeachers;

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'schedule_date_exception_lesson_teacher', 'schedule_date_exception_lesson_id')
            ->withPivot('user_id')->orderBy('teachers.id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function semesterSchedule(): BelongsTo
    {
        return $this->belongsTo(SemesterSchedule::class);
    }

    public function scheduleDateException(): BelongsTo
    {
        return $this->belongsTo(ScheduleDateException::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function sourceWeeklyRule(): BelongsTo
    {
        return $this->belongsTo(WeeklyLessonRule::class, 'source_weekly_rule_id');
    }

    protected function casts(): array
    {
        return [
            'pair_number' => 'integer',
        ];
    }
}
