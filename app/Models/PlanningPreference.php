<?php

namespace App\Models;

use Database\Factories\PlanningPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'max_daily_study_minutes', 'max_weekly_study_minutes', 'preferred_break_minutes', 'min_session_minutes', 'max_session_minutes'])]
class PlanningPreference extends Model
{
    /** @use HasFactory<PlanningPreferenceFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'max_daily_study_minutes' => 'integer',
            'max_weekly_study_minutes' => 'integer',
            'preferred_break_minutes' => 'integer',
            'min_session_minutes' => 'integer',
            'max_session_minutes' => 'integer',
        ];
    }
}
