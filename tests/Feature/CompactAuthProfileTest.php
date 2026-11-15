<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactAuthProfileTest extends TestCase
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
        return array_replace(['name' => 'Student', 'email' => 'student@example.test', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'], $overrides);
    }

    private function assertPublicUser(TestResponse $response, User $user): void
    {
        $response->assertExactJson(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'email_verified' => $user->hasVerifiedEmail(), 'reminder_email_enabled' => false, 'session_reminder_in_app_enabled' => false, 'session_reminder_email_enabled' => false, 'session_reminder_lead_minutes' => 10, 'timezone' => $user->timezone]]);
    }

    public function test_registration_persists_hash_defaults_and_authenticates_a_rotated_session(): void
    {
        $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $oldSession = session()->getId();
        $response = $this->postJson('/api/auth/register', $this->registration())->assertCreated();
        $user = User::sole();
        $this->assertSame('UTC', $user->timezone);
        $this->assertNotSame('secure-password', $user->password);
        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertNull($user->remember_token);
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertPublicUser($response, $user);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_registration_accepts_an_iana_timezone(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['timezone' => 'Europe/Kyiv']))->assertCreated()->assertJsonPath('data.timezone', 'Europe/Kyiv');
        $this->assertDatabaseHas('users', ['email' => 'student@example.test', 'timezone' => 'Europe/Kyiv']);
    }

    #[DataProvider('invalidRegistrations')]
    public function test_invalid_registration_is_rejected(array $overrides, array $fields): void
    {
        $this->postJson('/api/auth/register', $this->registration($overrides))->assertUnprocessable()->assertJsonValidationErrors($fields);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public static function invalidRegistrations(): array
    {
        return ['required fields' => [['name' => '', 'email' => '', 'password' => ''], [0 => 'name', 1 => 'email', 2 => 'password']]];
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
}
