<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'reminder_email_enabled' => $this->reminder_email_enabled,
            'session_reminder_in_app_enabled' => $this->session_reminder_in_app_enabled,
            'session_reminder_email_enabled' => $this->session_reminder_email_enabled,
            'session_reminder_lead_minutes' => $this->session_reminder_lead_minutes,
            'timezone' => $this->timezone,
        ];
    }
}
