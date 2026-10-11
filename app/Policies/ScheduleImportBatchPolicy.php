<?php

namespace App\Policies;

use App\Models\ScheduleImportBatch;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ScheduleImportBatchPolicy
{
    public function view(User $user, ScheduleImportBatch $batch): Response
    {
        return $user->id === $batch->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
