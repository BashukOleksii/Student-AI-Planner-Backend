<?php

namespace App\Policies;

use App\Models\EducationInstitution;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EducationInstitutionPolicy
{
    public function view(User $user, EducationInstitution $institution): Response
    {
        return $user->id === $institution->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, EducationInstitution $institution): Response
    {
        return $user->id === $institution->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, EducationInstitution $institution): Response
    {
        return $user->id === $institution->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
