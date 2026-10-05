<?php

namespace App\Models;

use App\Enums\StudySessionStatus;
use Database\Factories\StudySessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'task_id', 'subtask_id', 'rescheduled_from_session_id', 'starts_at', 'ends_at', 'status', 'completed_at', 'actual_minutes'])]
class StudySession extends Model
{
    /** @use HasFactory<StudySessionFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class);
    }

    public function rescheduledFromSession(): BelongsTo
    {
        return $this->belongsTo(StudySession::class, 'rescheduled_from_session_id');
    }

    public function rescheduledToSession(): HasOne
    {
        return $this->hasOne(StudySession::class, 'rescheduled_from_session_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    protected function casts(): array
    {
        return [
            'status' => StudySessionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'completed_at' => 'datetime',
            'actual_minutes' => 'integer',
        ];
    }
}
