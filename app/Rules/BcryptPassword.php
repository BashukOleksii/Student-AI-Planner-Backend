<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/** Reject values bcrypt would truncate or cannot hash, including multibyte passwords. */
class BcryptPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Hash::getDefaultDriver() === 'bcrypt' && is_string($value)
            && (strlen($value) > 72 || str_contains($value, "\0"))) {
            $fail('The password must not contain null bytes or exceed 72 UTF-8 bytes.');
        }
    }
}
