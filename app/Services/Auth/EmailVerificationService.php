<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class EmailVerificationService
{
    public function send(User $owner): bool
    {
        $user = User::findOrFail($owner->getKey());
        if ($user->hasVerifiedEmail()) {
            return false;
        }
        $user->sendEmailVerificationNotification();

        return true;
    }

    public function verificationUrl(User $user): string
    {
        $origin = config('app.frontend_url');
        $parts = is_string($origin) ? parse_url($origin) : false;
        if (! is_string($origin) || filter_var($origin, FILTER_VALIDATE_URL) === false || ! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])
            || array_intersect(array_keys($parts), ['user', 'pass', 'query', 'fragment']) !== []
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || (app()->isProduction() && $parts['scheme'] !== 'https')) {
            throw new RuntimeException('Email verification frontend configuration is unavailable.');
        }
        // Sign the API path, not the Vue URL or a request-supplied host.
        $path = URL::temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), [
            'id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification()),
        ], absolute: false);

        return rtrim($origin, '/').'/verify-email?'.http_build_query(['verification_path' => $path], '', '&', PHP_QUERY_RFC3986);
    }

    public function confirm(User $owner, Request $request): User
    {
        return $owner->getConnection()->transaction(function () use ($owner, $request): User {
            $user = User::whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            // Revalidate after acquiring the same lock used by profile email changes.
            abort_unless(URL::hasValidSignature($request, absolute: false)
                && hash_equals((string) $user->getKey(), (string) $request->route('id'))
                && hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash')), 403);
            if (! $user->hasVerifiedEmail()) {
                if (! $user->markEmailAsVerified()) {
                    throw new RuntimeException('Email verification persistence failed.');
                }
                event(new Verified($user));
            }

            return $user;
        });
    }
}
