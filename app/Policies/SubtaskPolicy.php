<?php

namespace App\Policies;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SubtaskPolicy
{
    public function view(User $user, Subtask $subtask, Task $task): Response
    {
        return $task->user_id === $user->id && $subtask->task_id === $task->id
            && ! $task->trashed() && ! $subtask->trashed() ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Subtask $subtask, Task $task): Response
    {
        return $this->view($user, $subtask, $task);
    }
}
