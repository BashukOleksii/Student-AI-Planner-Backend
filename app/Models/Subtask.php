<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\SubtaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['task_id', 'title', 'description', 'position', 'status', 'estimated_minutes', 'deadline_at', 'completed_at'])]
class Subtask extends Model
{
    /** @use HasFactory<SubtaskFactory> */
    use HasFactory, SoftDeletes;

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function studySessions(): HasMany
    {
        return $this->hasMany(StudySession::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'position' => 'integer',
            'estimated_minutes' => 'integer',
            'deadline_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
