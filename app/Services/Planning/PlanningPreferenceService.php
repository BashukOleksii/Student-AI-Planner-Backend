<?php

namespace App\Services\Planning;

use App\Models\PlanningPreference;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanningPreferenceService
{
    public function replace(User $user, array $values): PlanningPreference
    {
        $values = Arr::only($values, [
            'max_daily_study_minutes', 'max_weekly_study_minutes', 'preferred_break_minutes',
            'min_session_minutes', 'max_session_minutes',
        ]);

        $errors = [];
        if ($values['min_session_minutes'] !== null && $values['max_session_minutes'] !== null
            && $values['max_session_minutes'] < $values['min_session_minutes']) {
            $errors['max_session_minutes'] = ['Максимальна тривалість сесії має бути не меншою за мінімальну.'];
        }
        if ($values['max_daily_study_minutes'] !== null && $values['max_weekly_study_minutes'] !== null
            && $values['max_weekly_study_minutes'] < $values['max_daily_study_minutes']) {
            $errors['max_weekly_study_minutes'] = ['Тижневий ліміт навчання має бути не меншим за денний.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($user, $values): PlanningPreference {
            // Serialize first materialization as well as subsequent replacements.
            User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            return $user->planningPreference()->updateOrCreate([], $values)->refresh();
        });
    }
}
