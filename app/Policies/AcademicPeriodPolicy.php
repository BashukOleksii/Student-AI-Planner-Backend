<?php

namespace App\Policies;

use App\Models\AcademicPeriod;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AcademicPeriodPolicy
{
    public function view(User $user, AcademicPeriod $period): Response
    {
        return $user->id === $period->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, AcademicPeriod $period): Response
    {
        return $user->id === $period->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, AcademicPeriod $period): Response
    {
        return $user->id === $period->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
