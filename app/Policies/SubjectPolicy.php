<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SubjectPolicy
{
    public function view(User $user, Subject $subject): Response
    {
        return $user->id === $subject->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Subject $subject): Response
    {
        return $user->id === $subject->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, Subject $subject): Response
    {
        return $user->id === $subject->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
