<?php

namespace App\Services\Academic;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeacherService
{
    public function create(User $user, array $values): Teacher
    {
        return DB::transaction(function () use ($user, $values): Teacher {
            $this->lockOwner($user);
            $candidate = ['education_institution_id' => null, ...Arr::only($values, ['education_institution_id', 'name'])];
            $this->validateInstitution($user, $candidate['education_institution_id']);

            return $user->teachers()->create($candidate)->refresh();
        });
    }

    public function update(User $user, Teacher $teacher, array $values): Teacher
    {
        return DB::transaction(function () use ($user, $teacher, $values): Teacher {
            $this->lockOwner($user);
            $teacher = $user->teachers()->lockForUpdate()->findOrFail($teacher->getKey());
            $candidate = array_replace($teacher->only(['education_institution_id', 'name']), Arr::only($values, ['education_institution_id', 'name']));
            $this->validateInstitution($user, $candidate['education_institution_id']);
            $teacher->update($candidate);

            return $teacher->refresh();
        });
    }

    public function delete(User $user, Teacher $teacher): void
    {
        DB::transaction(function () use ($user, $teacher): void {
            $this->lockOwner($user);
            $user->teachers()->lockForUpdate()->findOrFail($teacher->getKey())->delete();
        });
    }

    private function lockOwner(User $user): void
    {
        User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function validateInstitution(User $user, ?int $institutionId): void
    {
        if ($institutionId !== null && $user->educationInstitutions()->whereKey($institutionId)->lockForUpdate()->first() === null) {
            throw ValidationException::withMessages(['education_institution_id' => ['The selected education institution is invalid.']]);
        }
    }
}
