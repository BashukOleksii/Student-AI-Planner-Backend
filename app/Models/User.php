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

#[Fillable(['name', 'email', 'password', 'timezone', 'reminder_email_enabled'])]
#[Hidden(['password', 'remember_token', 'reminder_email_version'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'reminder_email_enabled' => false,
        'reminder_email_version' => 0,
    ];

    public function recoverySuggestions(): HasMany
    {
        return $this->hasMany(RecoverySuggestion::class);
    }

    public function semesterSchedules(): HasMany
    {
        return $this->hasMany(SemesterSchedule::class);
    }

    public function semesterScheduleImports(): HasMany
    {
        return $this->hasMany(SemesterScheduleImport::class);
    }

    public function planningPreference(): HasOne
    {
        return $this->hasOne(PlanningPreference::class);
    }

    public function studyAvailabilityWindows(): HasMany
    {
        return $this->hasMany(StudyAvailabilityWindow::class);
    }

    public function educationInstitutions(): HasMany
    {
        return $this->hasMany(EducationInstitution::class);
    }

    public function academicPeriods(): HasMany
    {
        return $this->hasMany(AcademicPeriod::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    public function scheduleImportBatches(): HasMany
    {
        return $this->hasMany(ScheduleImportBatch::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function studySessions(): HasMany
    {
        return $this->hasMany(StudySession::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'reminder_email_enabled' => 'boolean',
            'reminder_email_version' => 'integer',
            'password' => 'hashed',
        ];
    }
}
