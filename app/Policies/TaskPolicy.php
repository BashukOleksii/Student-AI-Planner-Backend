<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TaskPolicy
{
    public function view(User $user, Task $task): Response
    {
        return $user->id === $task->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Task $task): Response
    {
        return $user->id === $task->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
