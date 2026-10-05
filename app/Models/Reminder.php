<?php

namespace App\Models;

use App\Enums\ReminderAnchor;
use App\Enums\ReminderStatus;
use Database\Factories\ReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'task_id', 'subtask_id', 'study_session_id', 'message', 'trigger_at', 'anchor', 'offset_minutes', 'status', 'sent_at'])]
class Reminder extends Model
{
    /** @use HasFactory<ReminderFactory> */
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

    public function studySession(): BelongsTo
    {
        return $this->belongsTo(StudySession::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ReminderStatus::class,
            'anchor' => ReminderAnchor::class,
            'trigger_at' => 'datetime',
            'sent_at' => 'datetime',
            'offset_minutes' => 'integer',
        ];
    }
}
