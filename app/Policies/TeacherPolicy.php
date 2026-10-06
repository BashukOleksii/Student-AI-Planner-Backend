<?php

namespace App\Policies;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TeacherPolicy
{
    public function view(User $user, Teacher $teacher): Response
    {
        return $user->id === $teacher->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Teacher $teacher): Response
    {
        return $user->id === $teacher->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, Teacher $teacher): Response
    {
        return $user->id === $teacher->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
