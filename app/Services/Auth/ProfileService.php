<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProfileService
{
    public function update(User $owner, array $values): User
    {
        return $owner->getConnection()->transaction(function () use ($owner, $values): User {
            $user = User::whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            $user->fill($values);
            if ($user->isDirty('email')) {
                $user->forceFill(['email_verified_at' => null, 'reminder_email_enabled' => false,
                    'reminder_email_version' => $user->reminder_email_version + 1]);
            }
            if (($values['reminder_email_enabled'] ?? false) && ! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'reminder_email_enabled' => ['Спочатку підтвердьте поточну email-адресу.'],
                ]);
            }
            if (! $user->save()) {
                throw new RuntimeException('Profile persistence failed.');
            }

            return $user;
        });
    }
}
