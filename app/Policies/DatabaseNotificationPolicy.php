<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Notifications\DatabaseNotification;

class DatabaseNotificationPolicy
{
    public function view(User $user, DatabaseNotification $notification): Response
    {
        return $notification->notifiable_type === $user->getMorphClass() && $notification->notifiable_id === $user->id
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, DatabaseNotification $notification): Response
    {
        return $this->view($user, $notification);
    }
}
