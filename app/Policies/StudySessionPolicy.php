<?php

namespace App\Policies;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StudySessionPolicy
{
    public function view(User $user, StudySession $studySession): Response
    {
        return $user->id === $studySession->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, StudySession $studySession): Response
    {
        return $this->view($user, $studySession);
    }
}
