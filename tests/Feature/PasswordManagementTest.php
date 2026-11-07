<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use App\Services\Auth\PasswordManagementService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSpaBrowser;
use Tests\TestCase;

class PasswordManagementTest extends TestCase
{
    use DatabaseTruncation, InteractsWithSpaBrowser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://planner.example.test', 'queue.default' => 'database']);
        Notification::fake();
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function switchBrowser(array $cookies): void
    {
        $this->browserCookies = $cookies;
        $this->unencryptedCookies = $this->defaultCookies = [];
    }

    private function secondLogin(User $user): array
    {
        $this->switchBrowser([]);
        $this->startBrowser();
        $this->browser('POST', '/api/auth/login', ['email' => $user->email, 'password' => 'integration-password'])->assertOk();

        return $this->browserCookies;
    }

    private function workOne(): void
    {
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();
    }

    private function newPassword(string $password = 'new-secure-password'): array
    {
        return ['password' => $password, 'password_confirmation' => $password];
    }

    public function test_change_checks_current_password_preserves_current_login_and_revokes_only_other_owned_sessions(): void
    {
        $identity = $this->registerBrowser();
        $owner = User::findOrFail($identity['id']);
        $owner->forceFill(['remember_token' => 'old-remember-token'])->save();
        $oldReset = Password::createToken($owner);
        $current = $this->browserCookies;
        $other = $this->secondLogin($owner); // Intentionally never prime this session with GET /profile.
        $foreign = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'foreign-session', 'user_id' => $foreign->id, 'payload' => '', 'last_activity' => time()]);
        $this->switchBrowser($current);
        $this->browser('PATCH', '/api/profile/password', ['current_password' => 'incorrect', ...$this->newPassword()])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('integration-password', $owner->fresh()->password));
        $this->assertSame(2, DB::table('sessions')->where('user_id', $owner->id)->count());
        $this->browser('PATCH', '/api/profile/password', ['current_password' => 'integration-password',
            ...$this->newPassword(), 'user_id' => $foreign->id, 'remember_token' => 'injected'])
            ->assertOk()->assertExactJson(['message' => 'Password changed successfully.']);
        $this->assertTrue(Hash::check('new-secure-password', $owner->fresh()->password));
        $this->assertNotSame('old-remember-token', $owner->fresh()->remember_token);
        $this->assertFalse(Password::tokenExists($owner->fresh(), $oldReset));
        $this->assertNotSame($current[config('session.cookie')], $this->browserCookies[config('session.cookie')]);
        $this->browser('GET', '/api/profile')->assertOk()->assertJsonPath('data.id', $owner->id);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $owner->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session', 'user_id' => $foreign->id]);
        $this->switchBrowser($other);
        $this->browser('GET', '/api/profile')->assertUnauthorized();
        Notification::assertNothingSent();
    }

    public function test_authentication_validation_and_existing_spa_csrf_protections(): void
    {
        $this->startBrowser();
        $this->browser('PATCH', '/api/profile/password', ['current_password' => 'anything', ...$this->newPassword()])->assertUnauthorized();
        $this->registerBrowser();
        $this->browser('PATCH', '/api/profile/password')->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password', 'password', 'password_confirmation']);
        foreach ([['password' => 'short', 'password_confirmation' => 'short'],
            ['password' => 'long-enough', 'password_confirmation' => 'mismatch'],
            $this->newPassword(str_repeat('я', 37))] as $body) {
            $this->browser('PATCH', '/api/profile/password', ['current_password' => 'integration-password', ...$body])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->browser('POST', '/api/auth/forgot-password', ['email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->browser('POST', '/api/auth/reset-password')->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'token', 'password', 'password_confirmation']);
        foreach ([['PATCH', '/api/profile/password'], ['POST', '/api/auth/forgot-password'], ['POST', '/api/auth/reset-password']] as [$method, $url]) {
            $this->browser($method, $url, [], ['X-XSRF-TOKEN' => ''])->assertStatus(419);
        }
        $this->assertDatabaseCount('jobs', 0);
        Notification::assertNothingSent();
    }

    public function test_recovery_is_uniform_queued_encrypted_broker_throttled_and_uses_only_the_trusted_url(): void
    {
        $this->startBrowser();
        $owner = User::factory()->create(['email' => 'student+tag@example.test']);
        $expected = ['message' => 'If the email address is eligible, you will receive a password reset link.'];
        $first = $this->browser('POST', '/api/auth/forgot-password', ['email' => $owner->email, 'redirect_url' => 'https://attacker.test'], ['Host' => 'attacker.test'])
            ->assertStatus(202)->assertExactJson($expected);
        $this->browser('POST', '/api/auth/forgot-password', ['email' => 'missing@example.test'])->assertStatus(202)->assertExactJson($expected);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
        foreach (DB::table('jobs')->pluck('payload') as $payload) {
            $this->assertStringNotContainsString($owner->email, $payload);
            $this->assertStringNotContainsString('missing@example.test', $payload);
        }
        $this->workOne();
        $this->workOne();
        Notification::assertSentToTimes($owner, ResetPassword::class, 1);
        $notification = Notification::sent($owner, ResetPassword::class)->sole();
        $this->assertSame('https://planner.example.test/reset-password?'.http_build_query([
            'token' => $notification->token, 'email' => $owner->email,
        ], '', '&', PHP_QUERY_RFC3986), $notification->toMail($owner)->actionUrl);
        $stored = DB::table('password_reset_tokens')->where('email', $owner->email)->value('token');
        $this->assertNotSame($notification->token, $stored);
        $this->assertTrue(Hash::check($notification->token, $stored));
        $this->assertStringNotContainsString($notification->token, $first->getContent());
        $this->browser('POST', '/api/auth/forgot-password', ['email' => $owner->email])->assertStatus(202)->assertExactJson($expected);
        $this->workOne();
        Notification::assertSentToTimes($owner, ResetPassword::class, 1);
        $this->assertTrue(Password::tokenExists($owner, $notification->token));
    }

    public function test_reset_rejects_bad_expired_unknown_and_reused_tokens_revokes_sessions_and_never_logs_in(): void
    {
        $identity = $this->registerBrowser();
        $owner = User::findOrFail($identity['id']);
        $owner->forceFill(['remember_token' => 'remember-before-reset'])->save();
        $firstSession = $this->browserCookies;
        $secondSession = $this->secondLogin($owner);
        $this->switchBrowser([]);
        $this->startBrowser();
        $credentials = ['email' => $owner->email, 'token' => 'invalid-token', ...$this->newPassword()];
        $invalid = $this->browser('POST', '/api/auth/reset-password', $credentials)->assertUnprocessable()->assertJsonValidationErrors('token')->json();
        $this->browser('POST', '/api/auth/reset-password', [...$credentials, 'email' => 'unknown@example.test'])
            ->assertUnprocessable()->assertExactJson($invalid);
        $token = Password::createToken($owner);
        DB::table('password_reset_tokens')->where('email', $owner->email)->update(['created_at' => now()->subMinutes(61)]);
        $this->browser('POST', '/api/auth/reset-password', [...$credentials, 'token' => $token])->assertUnprocessable()->assertExactJson($invalid);
        $this->assertTrue(Hash::check('integration-password', $owner->fresh()->password));
        $token = Password::createToken($owner);
        $this->browser('POST', '/api/auth/reset-password', [...$credentials, 'token' => $token, 'password_confirmation' => 'mismatch'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Password::tokenExists($owner, $token));
        $this->browser('POST', '/api/auth/reset-password', [...$credentials, 'token' => $token])->assertOk()
            ->assertExactJson(['message' => 'Password reset successfully. Sign in with your new password.']);
        $hash = $owner->fresh()->password;
        $this->assertTrue(Hash::check('new-secure-password', $hash));
        $this->assertNotSame('remember-before-reset', $owner->fresh()->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $owner->id)->count());
        $this->browser('GET', '/api/profile')->assertUnauthorized();
        $this->browser('POST', '/api/auth/reset-password', [...$credentials, 'token' => $token])->assertUnprocessable()->assertExactJson($invalid);
        $this->assertSame($hash, $owner->fresh()->password);
        foreach ([$firstSession, $secondSession] as $cookies) {
            $this->switchBrowser($cookies);
            $this->browser('GET', '/api/profile')->assertUnauthorized();
        }
        $this->switchBrowser([]);
        $this->startBrowser();
        $this->browser('POST', '/api/auth/login', ['email' => $owner->email, 'password' => 'integration-password'])->assertUnprocessable();
        $this->browser('POST', '/api/auth/login', ['email' => $owner->email, 'password' => 'new-secure-password'])->assertOk();
    }

    public function test_reset_in_the_targets_own_authenticated_browser_logs_it_out(): void
    {
        $identity = $this->registerBrowser();
        $owner = User::findOrFail($identity['id']);
        $this->browser('POST', '/api/auth/reset-password', ['email' => $owner->email, 'token' => Password::createToken($owner), ...$this->newPassword()])->assertOk();
        $this->browser('GET', '/api/profile')->assertUnauthorized();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $owner->id)->count());
    }

    public function test_recovery_and_reset_ip_throttles_do_not_depend_on_account_existence(): void
    {
        $this->startBrowser();
        for ($i = 0; $i < 5; $i++) {
            $this->browser('POST', '/api/auth/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(202);
        }
        $this->browser('POST', '/api/auth/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(429)->assertHeader('Retry-After');
        for ($i = 0; $i < 10; $i++) {
            $this->browser('POST', '/api/auth/reset-password', ['email' => 'unknown@example.test', 'token' => 'invalid', ...$this->newPassword()])->assertUnprocessable();
        }
        $this->browser('POST', '/api/auth/reset-password', ['email' => 'unknown@example.test', 'token' => 'invalid', ...$this->newPassword()])->assertStatus(429);
        Notification::assertNothingSent();
    }

    public function test_unsafe_configuration_and_delivery_failures_are_redacted_without_enumeration(): void
    {
        $this->startBrowser();
        $owner = User::factory()->create();
        config(['app.debug' => true, 'app.frontend_url' => 'https://attacker.test/?redirect=unsafe']);
        $this->browser('POST', '/api/auth/forgot-password', ['email' => $owner->email])->assertStatus(503)
            ->assertExactJson(['message' => 'Password management is temporarily unavailable.']);
        $this->browser('POST', '/api/auth/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(503);
        $this->assertDatabaseCount('jobs', 0);
        config(['app.frontend_url' => 'https://planner.example.test', 'mail.default' => 'log']);
        $this->app['env'] = 'production';
        $this->browser('POST', '/api/auth/forgot-password', ['email' => $owner->email])->assertStatus(503);
        $this->app['env'] = 'local';
        Log::spy();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('PRIVATE password, reset token and SMTP credentials'));
        (new SendPasswordResetLink($owner->email))->handle(app(PasswordManagementService::class));
        Log::shouldHaveReceived('warning')->once()->with('password.delivery_failed');
        Log::shouldNotHaveReceived('error');
    }
}
