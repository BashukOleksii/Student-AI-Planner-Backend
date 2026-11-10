<?php

namespace App\Policies;

use App\Models\SemesterSchedule;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SemesterSchedulePolicy
{
    public function update(User $user, SemesterSchedule $schedule): Response
    {
        return $this->view($user, $schedule);
    }

    public function view(User $user, SemesterSchedule $schedule): Response
    {
        return $user->id === $schedule->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
