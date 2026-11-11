<?php

namespace App\Models;

use Database\Factories\TeacherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'education_institution_id', 'name'])]
class Teacher extends Model
{
    /** @use HasFactory<TeacherFactory> */
    use HasFactory;

    public function scheduleDateExceptionLessons(): BelongsToMany
    {
        return $this->belongsToMany(ScheduleDateExceptionLesson::class, 'schedule_date_exception_lesson_teacher')->withPivot('user_id');
    }

    public function weeklyLessonRules(): BelongsToMany
    {
        return $this->belongsToMany(WeeklyLessonRule::class, 'weekly_lesson_rule_teacher')->withPivot('user_id');
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class)->withPivot('user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function educationInstitution(): BelongsTo
    {
        return $this->belongsTo(EducationInstitution::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
}
