<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'timezone', 'reminder_email_enabled',
    'session_reminder_in_app_enabled', 'session_reminder_email_enabled', 'session_reminder_lead_minutes'])]
#[Hidden(['password', 'remember_token', 'reminder_email_version'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'reminder_email_enabled' => false,
        'reminder_email_version' => 0,
        'session_reminder_in_app_enabled' => false,
        'session_reminder_email_enabled' => false,
        'session_reminder_lead_minutes' => 10,
    ];

    /** @return HasMany<RecoverySuggestion, $this> */
    public function recoverySuggestions(): HasMany
    {
        return $this->hasMany(RecoverySuggestion::class);
    }

    /** @return HasMany<SemesterSchedule, $this> */
    public function semesterSchedules(): HasMany
    {
        return $this->hasMany(SemesterSchedule::class);
    }

    /** @return HasMany<SemesterScheduleImport, $this> */
    public function semesterScheduleImports(): HasMany
    {
        return $this->hasMany(SemesterScheduleImport::class);
    }

    /** @return HasOne<PlanningPreference, $this> */
    public function planningPreference(): HasOne
    {
        return $this->hasOne(PlanningPreference::class);
    }

    /** @return HasMany<StudyAvailabilityWindow, $this> */
    public function studyAvailabilityWindows(): HasMany
    {
        return $this->hasMany(StudyAvailabilityWindow::class);
    }

    /** @return HasMany<EducationInstitution, $this> */
    public function educationInstitutions(): HasMany
    {
        return $this->hasMany(EducationInstitution::class);
    }

    /** @return HasMany<AcademicPeriod, $this> */
    public function academicPeriods(): HasMany
    {
        return $this->hasMany(AcademicPeriod::class);
    }

    /** @return HasMany<Subject, $this> */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    /** @return HasMany<Teacher, $this> */
    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    /** @return HasMany<ScheduleImportBatch, $this> */
    public function scheduleImportBatches(): HasMany
    {
        return $this->hasMany(ScheduleImportBatch::class);
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<StudySession, $this> */
    public function studySessions(): HasMany
    {
        return $this->hasMany(StudySession::class);
    }

    /** @return HasMany<Reminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /** @return HasMany<AiConversation, $this> */
    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'reminder_email_enabled' => 'boolean',
            'reminder_email_version' => 'integer',
            'session_reminder_in_app_enabled' => 'boolean',
            'session_reminder_email_enabled' => 'boolean',
            'session_reminder_lead_minutes' => 'integer',
            'password' => 'hashed',
        ];
    }
}
