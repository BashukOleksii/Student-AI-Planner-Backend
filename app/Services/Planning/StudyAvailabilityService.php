<?php

namespace App\Services\Planning;

use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudyAvailabilityService
{
    public function create(User $user, array $values): StudyAvailabilityWindow
    {
        return DB::transaction(function () use ($user, $values): StudyAvailabilityWindow {
            $this->lockOwner($user);
            $candidate = Arr::only($values, ['day_of_week', 'starts_at', 'ends_at']);
            $this->validateInterval($user, $candidate);

            return $user->studyAvailabilityWindows()->create($candidate)->refresh();
        });
    }

    public function update(User $user, StudyAvailabilityWindow $window, array $values): StudyAvailabilityWindow
    {
        return DB::transaction(function () use ($user, $window, $values): StudyAvailabilityWindow {
            $this->lockOwner($user);
            // Re-read after locking so PATCH merges with the latest owned state.
            $window = $user->studyAvailabilityWindows()->lockForUpdate()->findOrFail($window->getKey());
            $candidate = array_replace(
                $window->only(['day_of_week', 'starts_at', 'ends_at']),
                Arr::only($values, ['day_of_week', 'starts_at', 'ends_at']),
            );
            $this->validateInterval($user, $candidate, $window->getKey());
            $window->update($candidate);

            return $window->refresh();
        });
    }

    public function delete(User $user, StudyAvailabilityWindow $window): void
    {
        DB::transaction(function () use ($user, $window): void {
            $this->lockOwner($user);
            $user->studyAvailabilityWindows()->findOrFail($window->getKey())->delete();
        });
    }

    private function lockOwner(User $user): void
    {
        // Even an empty collection needs a common lock to prevent concurrent overlaps.
        User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function validateInterval(User $user, array $candidate, ?int $excludeId = null): void
    {
        if ($candidate['starts_at'] >= $candidate['ends_at']) {
            throw ValidationException::withMessages([
                'ends_at' => ['The end time must be after the start time. Split overnight availability into two explicit windows.'],
            ]);
        }

        $overlaps = $user->studyAvailabilityWindows()
            ->where('day_of_week', $candidate['day_of_week'])
            ->where('starts_at', '<', $candidate['ends_at'])
            ->where('ends_at', '>', $candidate['starts_at'])
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->lockForUpdate()
            ->first() !== null;

        if ($overlaps) {
            throw ValidationException::withMessages([
                'starts_at' => ['This window overlaps existing availability on the same weekday.'],
            ]);
        }
    }
}
