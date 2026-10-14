<?php

namespace App\Policies;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReminderPolicy
{
    public function view(User $user, Reminder $reminder): Response
    {
        return $user->id === $reminder->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Reminder $reminder): Response
    {
        return $this->view($user, $reminder);
    }
}
