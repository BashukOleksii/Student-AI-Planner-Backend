<?php

namespace App\Services\Planning;

use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StudyAvailabilityService
{
    /** Read-only preflight. Never acquires row locks or calls a writer. */
    public function prepare(User $user, array $values, ?StudyAvailabilityWindow $window = null): array
    {
        if ($window !== null) {
            $window = $user->studyAvailabilityWindows()->findOrFail($window->getKey());
        }

        return $this->candidate($user, $values, $window, false);
    }

    public function create(User $user, array $values): StudyAvailabilityWindow
    {
        return DB::transaction(function () use ($user, $values): StudyAvailabilityWindow {
            $this->lockOwner($user);
            $candidate = $this->candidate($user, $values, null, true);
            $window = $user->studyAvailabilityWindows()->create($candidate);
            if (! $window->exists || $window->getKey() === null) {
                throw new RuntimeException('Availability creation failed.');
            }

            return $window->refresh();
        });
    }

    public function update(User $user, StudyAvailabilityWindow $window, array $values): StudyAvailabilityWindow
    {
        return DB::transaction(function () use ($user, $window, $values): StudyAvailabilityWindow {
            $this->lockOwner($user);
            // Re-read after locking so PATCH merges with the latest owned state.
            $window = $user->studyAvailabilityWindows()->lockForUpdate()->findOrFail($window->getKey());
            $candidate = $this->candidate($user, $values, $window, true);
            if (! $window->update($candidate)) {
                throw new RuntimeException('Availability update failed.');
            }

            return $window->refresh();
        });
    }

    public function delete(User $user, StudyAvailabilityWindow $window): void
    {
        DB::transaction(function () use ($user, $window): void {
            $this->lockOwner($user);
            if (! $user->studyAvailabilityWindows()->findOrFail($window->getKey())->delete()) {
                throw new RuntimeException('Availability deletion failed.');
            }
        });
    }

    private function lockOwner(User $user): void
    {
        // Even an empty collection needs a common lock to prevent concurrent overlaps.
        User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function candidate(User $user, array $values, ?StudyAvailabilityWindow $window, bool $lock): array
    {
        $candidate = array_replace(
            $window?->only(['day_of_week', 'starts_at', 'ends_at']) ?? [],
            Arr::only($values, ['day_of_week', 'starts_at', 'ends_at']),
        );
        Validator::make($candidate, StudyAvailabilityInputRules::rules(true))->validate();
        $this->validateInterval($user, $candidate, $window?->getKey(), $lock);

        return $candidate;
    }

    private function validateInterval(User $user, array $candidate, ?int $excludeId, bool $lock): void
    {
        if ($candidate['starts_at'] >= $candidate['ends_at']) {
            throw ValidationException::withMessages([
                'ends_at' => ['Час завершення має бути пізніше часу початку. Розділіть нічну доступність на два окремі проміжки.'],
            ]);
        }

        $overlaps = $user->studyAvailabilityWindows()
            ->where('day_of_week', $candidate['day_of_week'])
            ->where('starts_at', '<', $candidate['ends_at'])
            ->where('ends_at', '>', $candidate['starts_at'])
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first() !== null;

        if ($overlaps) {
            throw ValidationException::withMessages([
                'starts_at' => ['Проміжок перетинається з наявною доступністю цього дня тижня.'],
            ]);
        }
    }
}
