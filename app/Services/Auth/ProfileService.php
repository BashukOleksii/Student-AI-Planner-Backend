<?php

namespace App\Services\Auth;

use App\Models\User;
use RuntimeException;

class ProfileService
{
    public function update(User $owner, array $values): User
    {
        return $owner->getConnection()->transaction(function () use ($owner, $values): User {
            $user = User::whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            $user->fill($values);
            if ($user->isDirty('email')) {
                $user->forceFill(['email_verified_at' => null]);
            }
            if (! $user->save()) {
                throw new RuntimeException('Profile persistence failed.');
            }

            return $user;
        });
    }
}
