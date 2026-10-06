<?php

namespace App\Services\Academic;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubjectService
{
    public function create(User $user, array $values): Subject
    {
        return DB::transaction(function () use ($user, $values): Subject {
            $this->lockOwner($user);
            $candidate = ['education_institution_id' => null, 'code' => null, 'color' => null,
                ...Arr::only($values, ['education_institution_id', 'name', 'code', 'color'])];
            $this->validateCandidate($user, $candidate);

            return $user->subjects()->create($candidate)->refresh();
        });
    }

    public function update(User $user, Subject $subject, array $values): Subject
    {
        return DB::transaction(function () use ($user, $subject, $values): Subject {
            $this->lockOwner($user);
            $subject = $user->subjects()->lockForUpdate()->findOrFail($subject->getKey());
            $candidate = array_replace($subject->only(['education_institution_id', 'name', 'code', 'color']),
                Arr::only($values, ['education_institution_id', 'name', 'code', 'color']));
            $this->validateCandidate($user, $candidate, $subject->getKey());
            $subject->update($candidate);

            return $subject->refresh();
        });
    }

    public function delete(User $user, Subject $subject): void
    {
        DB::transaction(function () use ($user, $subject): void {
            $this->lockOwner($user);
            $subject = $user->subjects()->lockForUpdate()->findOrFail($subject->getKey());
            if ($subject->lessons()->withTrashed()->lockForUpdate()->first() !== null) {
                throw ValidationException::withMessages(['subject' => ['A subject with lesson history cannot be deleted.']]);
            }

            $subject->delete();
        });
    }

    private function lockOwner(User $user): void
    {
        User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function validateCandidate(User $user, array $candidate, ?int $excludeId = null): void
    {
        if ($candidate['education_institution_id'] !== null
            && $user->educationInstitutions()->whereKey($candidate['education_institution_id'])->lockForUpdate()->first() === null) {
            throw ValidationException::withMessages(['education_institution_id' => ['The selected education institution is invalid.']]);
        }

        $duplicate = $user->subjects()->where('name', $candidate['name'])
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))->lockForUpdate()->first();
        if ($duplicate !== null) {
            throw ValidationException::withMessages(['name' => ['A subject with this name already exists.']]);
        }
    }
}
