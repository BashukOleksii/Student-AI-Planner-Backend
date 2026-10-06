<?php

namespace App\Services\Academic;

use App\Models\AcademicPeriod;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicPeriodService
{
    public function create(User $user, array $values): AcademicPeriod
    {
        return DB::transaction(function () use ($user, $values): AcademicPeriod {
            $this->lockOwner($user);
            $candidate = ['education_institution_id' => null, ...Arr::only($values, ['education_institution_id', 'name', 'starts_on', 'ends_on'])];
            $this->validateCandidate($user, $candidate);

            return $user->academicPeriods()->create($candidate)->refresh();
        });
    }

    public function update(User $user, AcademicPeriod $period, array $values): AcademicPeriod
    {
        return DB::transaction(function () use ($user, $period, $values): AcademicPeriod {
            $this->lockOwner($user);
            $period = $user->academicPeriods()->lockForUpdate()->findOrFail($period->getKey());
            $candidate = array_replace([
                'education_institution_id' => $period->education_institution_id,
                'name' => $period->name,
                'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d'),
            ], Arr::only($values, ['education_institution_id', 'name', 'starts_on', 'ends_on']));
            $this->validateCandidate($user, $candidate, $period->getKey());
            $period->update($candidate);

            return $period->refresh();
        });
    }

    public function delete(User $user, AcademicPeriod $period): void
    {
        DB::transaction(function () use ($user, $period): void {
            $this->lockOwner($user);
            $period = $user->academicPeriods()->lockForUpdate()->findOrFail($period->getKey());
            if ($period->scheduleImportBatches()->lockForUpdate()->first() !== null
                || $period->lessons()->withTrashed()->lockForUpdate()->first() !== null) {
                throw ValidationException::withMessages([
                    'academic_period' => ['An academic period with schedule or import history cannot be deleted.'],
                ]);
            }

            $period->delete();
        });
    }

    private function lockOwner(User $user): void
    {
        User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function validateCandidate(User $user, array $candidate, ?int $excludeId = null): void
    {
        if ($candidate['starts_on'] > $candidate['ends_on']) {
            throw ValidationException::withMessages(['ends_on' => ['The end date must be on or after the start date.']]);
        }

        if ($candidate['education_institution_id'] !== null
            && $user->educationInstitutions()->whereKey($candidate['education_institution_id'])->lockForUpdate()->first() === null) {
            throw ValidationException::withMessages(['education_institution_id' => ['The selected education institution is invalid.']]);
        }

        $duplicate = $user->academicPeriods()->where('name', $candidate['name'])->where('starts_on', $candidate['starts_on'])
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->lockForUpdate()->first();
        if ($duplicate !== null) {
            throw ValidationException::withMessages(['name' => ['An academic period with this name and start date already exists.']]);
        }
    }
}
