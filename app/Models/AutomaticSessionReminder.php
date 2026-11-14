<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'study_session_id', 'channel', 'lead_minutes', 'session_starts_at', 'trigger_at', 'status', 'notification_id'])]
class AutomaticSessionReminder extends Model
{
    protected function casts(): array
    {
        return ['lead_minutes' => 'integer', 'session_starts_at' => 'immutable_datetime', 'trigger_at' => 'immutable_datetime'];
    }
}
