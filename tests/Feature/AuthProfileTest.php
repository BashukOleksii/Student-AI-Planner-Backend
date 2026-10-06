<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost:5173'], 'cors.allowed_origins' => ['http://localhost:5173']]);
        $this->withHeader('Origin', 'http://localhost:5173')->withCredentials();
    }

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Student',
            'email' => 'student@example.test',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ], $overrides);
    }

    private function assertPublicUser(TestResponse $response, User $user): void
    {
        $response->assertExactJson(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->timezone,
        ]]);
    }

    public function test_registration_persists_hash_defaults_and_authenticates_a_rotated_session(): void
    {
        $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $oldSession = session()->getId();

        $response = $this->postJson('/api/auth/register', $this->registration([
            'user_id' => 12345,
            'remember_token' => 'injected',
            'email_verified_at' => now()->toISOString(),
        ]))->assertCreated();

        $user = User::sole();
        $this->assertSame('UTC', $user->timezone);
        $this->assertNotSame('secure-password', $user->password);
        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertNull($user->remember_token);
        $this->assertNull($user->email_verified_at);
        $this->assertNotSame(12345, $user->id);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertPublicUser($response, $user);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_registration_accepts_an_iana_timezone(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['timezone' => 'Europe/Kyiv']))
            ->assertCreated()->assertJsonPath('data.timezone', 'Europe/Kyiv');
        $this->assertDatabaseHas('users', ['email' => 'student@example.test', 'timezone' => 'Europe/Kyiv']);
    }

    #[DataProvider('invalidRegistrations')]
    public function test_invalid_registration_is_rejected(array $overrides, array $fields): void
    {
        $this->postJson('/api/auth/register', $this->registration($overrides))
            ->assertUnprocessable()->assertJsonValidationErrors($fields);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public static function invalidRegistrations(): array
    {
        return [
            'required fields' => [['name' => '', 'email' => '', 'password' => ''], ['name', 'email', 'password']],
            'invalid types' => [['name' => ['Student'], 'email' => 'invalid'], ['name', 'email']],
            'long fields' => [['name' => str_repeat('a', 256), 'email' => str_repeat('a', 250).'@example.test'], ['name', 'email']],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], ['password']],
            'mismatched confirmation' => [['password_confirmation' => 'different'], ['password']],
            'missing confirmation' => [['password_confirmation' => null], ['password']],
            'invalid timezone' => [['timezone' => 'Mars/Olympus'], ['timezone']],
            'offset timezone' => [['timezone' => '+03:00'], ['timezone']],
            'null timezone' => [['timezone' => null], ['timezone']],
        ];
    }

    public function test_duplicate_email_registration_is_rejected(): void
    {
        User::factory()->create(['email' => 'student@example.test']);
        $this->postJson('/api/auth/register', $this->registration())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_authenticates_and_rotates_session_without_issuing_a_token(): void
    {
        $user = User::factory()->create(['password' => 'secure-password'])->refresh();
        $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $oldSession = session()->getId();
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secure-password'])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertPublicUser($response, $user);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_bad_credentials_do_not_reveal_account_existence(): void
    {
        $user = User::factory()->create()->refresh();
        $wrongPassword = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $unknownEmail = $this->postJson('/api/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
        $this->assertGuest('web');
    }

    public function test_login_requires_valid_input(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    #[DataProvider('nonStatefulAuthRequests')]
    public function test_auth_requires_a_stateful_request_before_any_mutation(string $endpoint, ?string $origin): void
    {
        $this->withoutHeader('Origin');
        if ($origin !== null) {
            $this->withHeader('Origin', $origin);
        }

        if ($endpoint === 'login') {
            User::factory()->create(['email' => 'student@example.test', 'password' => 'secure-password']);
        }
        $expectedCount = User::count();

        $this->postJson('/api/auth/'.$endpoint, $this->registration())
            ->assertUnprocessable()->assertJsonValidationErrors('session');

        $this->assertDatabaseCount('users', $expectedCount);
        $this->assertGuest('web');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function nonStatefulAuthRequests(): array
    {
        $cases = [];
        foreach (['register', 'login'] as $endpoint) {
            $cases[$endpoint.' missing origin'] = [$endpoint, null];
            $cases[$endpoint.' untrusted origin'] = [$endpoint, 'http://untrusted.test'];
            $cases[$endpoint.' unconfigured port'] = [$endpoint, 'http://localhost:5174'];
        }

        return $cases;
    }

    public function test_configured_referer_can_establish_a_session_without_origin(): void
    {
        $this->withoutHeader('Origin')->withHeader('Referer', 'http://localhost:5173/register');

        $response = $this->postJson('/api/auth/register', $this->registration())->assertCreated();
        $user = User::sole();
        $this->assertPublicUser($response, $user);
        $this->assertAuthenticatedAs($user, 'web');

        $this->withUnencryptedCookie(config('session.cookie'), $response->getCookie(config('session.cookie'), false)->getValue());
        Auth::forgetGuards();
        $this->assertPublicUser($this->getJson('/api/profile')->assertOk(), $user);
    }

    public function test_private_endpoints_reject_unauthenticated_requests(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->patchJson('/api/profile', ['name' => 'Other'])->assertUnauthorized();
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    public function test_profile_returns_only_current_user_and_public_fields(): void
    {
        $user = User::factory()->create(['remember_token' => 'secret'])->refresh();
        $other = User::factory()->create()->refresh();
        $response = $this->actingAs($user, 'web')->getJson('/api/profile?user_id='.$other->id)->assertOk();
        $this->assertPublicUser($response, $user);
        $this->getJson('/api/user')->assertNotFound();
    }

    public function test_partial_profile_updates_and_own_email_are_accepted(): void
    {
        $user = User::factory()->create()->refresh();
        $this->actingAs($user, 'web')->patchJson('/api/profile', ['name' => 'Updated'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated', 'email' => $user->email, 'timezone' => $user->timezone]);
        $response = $this->patchJson('/api/profile', ['email' => $user->email, 'timezone' => 'Europe/Kyiv'])->assertOk();
        $this->assertPublicUser($response, $user->refresh());
        $this->patchJson('/api/profile', ['email' => 'updated@example.test'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'updated@example.test']);
        $this->patchJson('/api/profile', [])->assertOk();
    }

    public function test_another_users_email_cannot_be_taken(): void
    {
        $user = User::factory()->create()->refresh();
        $other = User::factory()->create()->refresh();
        $this->actingAs($user, 'web')->patchJson('/api/profile', ['email' => $other->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($user->email, $user->refresh()->email);
    }

    #[DataProvider('invalidProfiles')]
    public function test_invalid_profile_fields_are_rejected(array $payload, string $field): void
    {
        $user = User::factory()->create()->refresh();
        $original = $user->getAttributes();
        $this->actingAs($user, 'web')->patchJson('/api/profile', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($original, $user->refresh()->getAttributes());
    }

    public static function invalidProfiles(): array
    {
        return [
            [['timezone' => 'Mars/Olympus'], 'timezone'],
            [['timezone' => '+03:00'], 'timezone'],
            [['timezone' => null], 'timezone'],
            [['name' => ''], 'name'],
            [['email' => 'invalid'], 'email'],
        ];
    }

    public function test_payload_cannot_change_password_or_select_another_user(): void
    {
        $user = User::factory()->create()->refresh();
        $other = User::factory()->create()->refresh();
        $originalPassword = $user->password;
        $otherAttributes = $other->getAttributes();
        $response = $this->actingAs($user, 'web')->patchJson('/api/profile', [
            'user_id' => $other->id,
            'id' => $other->id,
            'name' => 'My new name',
            'password' => 'injected-password',
            'remember_token' => 'injected-token',
            'email_verified_at' => null,
        ])->assertOk();
        $this->assertPublicUser($response, $user->refresh());
        $this->assertSame('My new name', $user->name);
        $this->assertSame($originalPassword, $user->password);
        $this->assertSame($otherAttributes, $other->refresh()->getAttributes());
        $this->assertNotSame('injected-token', $user->remember_token);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_logout_invalidates_session_and_regenerates_csrf_token(): void
    {
        $user = User::factory()->create(['password' => 'secure-password'])->refresh();
        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secure-password'])->assertOk();
        $sessionId = session()->getId();
        $token = session()->token();
        $oldSessionCookie = $login->getCookie(config('session.cookie'), false)->getValue();
        $this->withUnencryptedCookie(config('session.cookie'), $oldSessionCookie);
        Auth::forgetGuards();
        $this->getJson('/api/profile')->assertOk();
        $logout = $this->postJson('/api/auth/logout')->assertNoContent();
        $this->assertGuest('web');
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->assertNull(session()->get(Auth::guard('web')->getName()));
        $this->withUnencryptedCookie(config('session.cookie'), $logout->getCookie(config('session.cookie'), false)->getValue());
        Auth::forgetGuards();
        $this->getJson('/api/profile')->assertUnauthorized();

        $this->withUnencryptedCookie(config('session.cookie'), $oldSessionCookie);
        Auth::forgetGuards();
        $this->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_csrf_cookie_flow_enforces_csrf_and_allows_credentialed_cors(): void
    {
        // Laravel normally skips CSRF validation during tests; enable it for this browser contract.
        $this->app['env'] = 'local';
        try {
            $csrf = $this->getJson('/sanctum/csrf-cookie')->assertNoContent()
                ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
                ->assertHeader('Access-Control-Allow-Credentials', 'true');
            $this->withUnencryptedCookie(config('session.cookie'), $csrf->getCookie(config('session.cookie'), false)->getValue());
            $this->postJson('/api/auth/register', $this->registration())->assertStatus(419);
            $this->withHeader('X-XSRF-TOKEN', $csrf->getCookie('XSRF-TOKEN', false)->getValue());
            $this->postJson('/api/auth/register', $this->registration())->assertCreated();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_cors_preflight_allows_configured_origin_but_not_other_origins(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173', 'http://localhost:5174']]);
        $this->options('/api/auth/login', [], ['Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type,x-xsrf-token'])
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->withHeader('Origin', 'http://untrusted.test')->options('/api/auth/login', [], ['Access-Control-Request-Method' => 'POST'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
