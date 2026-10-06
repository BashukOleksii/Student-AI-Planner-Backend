<?php

namespace App\Policies;

use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StudyAvailabilityWindowPolicy
{
    public function update(User $user, StudyAvailabilityWindow $window): Response
    {
        return $user->id === $window->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, StudyAvailabilityWindow $window): Response
    {
        return $user->id === $window->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
