<?php

namespace App\Services\Academic;

use App\Models\EducationInstitution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EducationInstitutionService
{
    public function delete(User $user, EducationInstitution $institution): void
    {
        DB::transaction(function () use ($user, $institution): void {
            User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $institution = $user->educationInstitutions()->lockForUpdate()->findOrFail($institution->getKey());

            foreach (['academicPeriods', 'subjects', 'teachers'] as $relation) {
                if ($institution->$relation()->where('user_id', '!=', $user->getKey())->lockForUpdate()->first() !== null) {
                    throw ValidationException::withMessages([
                        'education_institution' => ['This education institution cannot be deleted.'],
                    ]);
                }
            }

            $institution->delete();
        });
    }
}
