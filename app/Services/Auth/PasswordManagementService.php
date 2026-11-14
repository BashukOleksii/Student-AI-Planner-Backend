<?php

namespace App\Services\Auth;

use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PasswordManagementService
{
    public function requestReset(#[\SensitiveParameter] string $email): void
    {
        $this->assertDeliveryConfiguration();
        SendPasswordResetLink::dispatch($email);
    }

    public function sendResetLink(#[\SensitiveParameter] string $email): void
    {
        $this->assertDeliveryConfiguration();
        // Broker owns token hashing, expiry and per-account resend throttling.
        Password::broker()->sendResetLink(['email' => $email]);
    }

    public function change(User $owner, #[\SensitiveParameter] array $credentials, string $sessionId): User
    {
        $this->assertSessionConfiguration();

        return (new User)->getConnection()->transaction(function () use ($owner, $credentials, $sessionId): User {
            $user = User::whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            // Repeat under the owner lock: Form Request validation may have preceded another change.
            if (! Hash::check($credentials['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => [__('validation.current_password')]]);
            }
            $this->savePassword($user, $credentials['password']);
            Password::broker()->deleteToken($user);
            $this->sessions($user)->where('id', '!=', $sessionId)->delete();

            return $user;
        });
    }

    public function reset(#[\SensitiveParameter] array $credentials): User
    {
        $this->assertSessionConfiguration();

        return (new User)->getConnection()->transaction(function () use ($credentials): User {
            // Serialize changes/resets before Broker validation, so a consumed token cannot win twice.
            User::where('email', $credentials['email'])->lockForUpdate()->first();
            $updated = null;
            $status = Password::broker()->reset($credentials, function (User $user, string $password) use (&$updated): void {
                $this->savePassword($user, $password);
                $this->sessions($user)->delete();
                event(new PasswordReset($user));
                $updated = $user;
            });
            if ($status !== Password::PASSWORD_RESET || ! $updated instanceof User) {
                throw ValidationException::withMessages(['token' => ['Посилання для скидання пароля недійсне або прострочене.']]);
            }

            return $updated;
        });
    }

    public function resetUrl(User $user, #[\SensitiveParameter] string $token): string
    {
        // Also protect native Broker notifications invoked outside the recovery job.
        $this->assertDeliveryConfiguration();

        return $this->frontendOrigin().'/reset-password?'.http_build_query([
            'token' => $token, 'email' => $user->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function savePassword(User $user, #[\SensitiveParameter] string $password): void
    {
        $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)]);
        if (! $user->save()) {
            throw new RuntimeException('Password persistence failed.');
        }
    }

    private function sessions(User $user): Builder
    {
        return $user->getConnection()->table(config('session.table'))->where('user_id', $user->getKey());
    }

    private function assertSessionConfiguration(): void
    {
        $connection = (new User)->getConnection()->getName();
        $broker = config('auth.passwords.'.config('auth.defaults.passwords'));
        // Atomic revocation uses the existing database sessions and token table on this connection.
        if (config('session.driver') !== 'database' || (config('session.connection') ?? $connection) !== $connection
            || ! is_array($broker) || ($broker['driver'] ?? 'database') !== 'database'
            || ($broker['provider'] ?? null) !== 'users' || ($broker['connection'] ?? $connection) !== $connection) {
            throw new RuntimeException('Password session configuration is unavailable.');
        }
    }

    private function assertDeliveryConfiguration(): void
    {
        $this->frontendOrigin();
        if (app()->isProduction()) {
            $queue = config('queue.connections.'.config('queue.default').'.driver');
            $mail = config('mail.mailers.'.config('mail.default').'.transport');
            if (! in_array($queue, ['database', 'redis', 'sqs', 'beanstalkd'], true)
                || ! in_array($mail, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)) {
                throw new RuntimeException('Password delivery configuration is unavailable.');
            }
        }
    }

    private function frontendOrigin(): string
    {
        $origin = config('app.frontend_url');
        $parts = is_string($origin) ? parse_url($origin) : false;
        if (! is_string($origin) || filter_var($origin, FILTER_VALIDATE_URL) === false || ! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])
            || array_intersect(array_keys($parts), ['user', 'pass', 'query', 'fragment']) !== []
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || (app()->isProduction() && $parts['scheme'] !== 'https')) {
            throw new RuntimeException('Password frontend configuration is unavailable.');
        }

        return rtrim($origin, '/');
    }
}
