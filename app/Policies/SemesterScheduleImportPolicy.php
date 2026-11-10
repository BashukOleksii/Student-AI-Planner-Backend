<?php

namespace App\Policies;

use App\Models\SemesterScheduleImport;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SemesterScheduleImportPolicy
{
    public function view(User $user, SemesterScheduleImport $import): Response
    {
        return $user->id === $import->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
