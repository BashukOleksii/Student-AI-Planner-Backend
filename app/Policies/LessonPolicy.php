<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class LessonPolicy
{
    public function view(User $user, Lesson $lesson): Response
    {
        return $user->id === $lesson->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Lesson $lesson): Response
    {
        return $user->id === $lesson->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, Lesson $lesson): Response
    {
        return $user->id === $lesson->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
