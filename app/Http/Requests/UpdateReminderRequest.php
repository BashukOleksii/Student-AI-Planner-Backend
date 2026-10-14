<?php

namespace App\Http\Requests;

class UpdateReminderRequest extends StoreReminderRequest
{
    protected function creating(): bool
    {
        return false;
    }
}
