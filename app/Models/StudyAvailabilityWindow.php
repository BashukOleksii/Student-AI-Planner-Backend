<?php

namespace App\Models;

use Database\Factories\StudyAvailabilityWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'day_of_week', 'starts_at', 'ends_at'])]
class StudyAvailabilityWindow extends Model
{
    /** @use HasFactory<StudyAvailabilityWindowFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }
}
