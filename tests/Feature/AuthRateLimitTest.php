<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSpaBrowser;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use InteractsWithSpaBrowser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->withHeader('Origin', 'http://localhost:5173')->withCredentials();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);
    }

    private function login(mixed $email = 'student@example.test'): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong-password']);
    }

    private function assertThrottled(TestResponse $response, int $limit, int $retryAfter): void
    {
        $response->assertTooManyRequests()
            ->assertJsonPath('message', 'Too Many Attempts.')
            ->assertHeader('Retry-After', (string) $retryAfter)
            ->assertHeader('X-RateLimit-Limit', (string) $limit)
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    public function test_valid_credentials_still_work_on_the_fifth_login_request(): void
    {
        $user = User::factory()->create(['email' => 'student@example.test', 'password' => 'secure-password']);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->login()->assertUnprocessable()->assertJsonValidationErrors('email');
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secure-password'])
            ->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonMissingPath('token');
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_identity_limit_blocks_even_valid_credentials_and_expires_after_a_minute(): void
    {
        $user = User::factory()->create(['email' => 'student@example.test', 'password' => 'secure-password']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login()->assertUnprocessable();
        }

        $this->assertThrottled($this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'secure-password',
        ]), 5, 60);
        $this->assertGuest('web');
        $this->travel(15)->seconds();
        $this->assertThrottled($this->login(), 5, 45);
        $this->travel(46)->seconds();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secure-password'])->assertOk();
    }

    public function test_case_and_surrounding_whitespace_share_the_same_login_identity_bucket(): void
    {
        foreach (['student@example.test', 'STUDENT@example.test', ' Student@Example.Test ', 'student@EXAMPLE.TEST', 'student@example.test'] as $email) {
            $this->login($email)->assertUnprocessable();
        }

        $this->assertThrottled($this->login(' STUDENT@EXAMPLE.TEST '), 5, 60);
        $this->login('other@example.test')->assertUnprocessable();
    }

    public function test_different_identities_share_the_thirty_request_ip_limit(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->login('student-'.$attempt.'@example.test')->assertUnprocessable();
        }

        $this->assertThrottled($this->login('fresh@example.test'), 30, 60);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11']);
        $this->login('fresh@example.test')->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);
        $this->login('fresh@example.test')->assertUnprocessable();
    }

    public function test_same_email_has_independent_buckets_for_different_client_ips(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login()->assertUnprocessable();
        }
        $this->assertThrottled($this->login(), 5, 60);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11']);
        $this->login()->assertUnprocessable();
    }

    public function test_successful_registrations_count_toward_the_hourly_limit(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/register', [
                'name' => 'Student', 'email' => 'student-'.$attempt.'@example.test',
                'password' => 'secure-password', 'password_confirmation' => 'secure-password',
            ])->assertCreated()->assertJsonPath('data.email', 'student-'.$attempt.'@example.test');
        }

        $this->assertThrottled($this->postJson('/api/auth/register', [
            'name' => 'Blocked', 'email' => 'blocked@example.test',
            'password' => 'secure-password', 'password_confirmation' => 'secure-password',
        ]), 10, 3600);
        $this->assertDatabaseCount('users', 10);
        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.test']);
    }

    public function test_invalid_registrations_count_and_other_ips_remain_independent(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/register')->assertUnprocessable();
        }
        $this->assertThrottled($this->postJson('/api/auth/register'), 10, 3600);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11']);
        $this->postJson('/api/auth/register')->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);
        $this->travel(60)->minutes();
        $this->travel(1)->seconds();
        $this->postJson('/api/auth/register')->assertUnprocessable();
    }

    public function test_login_and_registration_have_separate_namespaces(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/register')->assertUnprocessable();
        }
        $this->assertThrottled($this->postJson('/api/auth/register'), 10, 3600);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login()->assertUnprocessable();
        }
        $this->assertThrottled($this->login(), 5, 60);
    }

    #[DataProvider('malformedEmails')]
    public function test_malformed_emails_keep_standard_validation(mixed $email): void
    {
        $this->login($email)->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public static function malformedEmails(): array
    {
        return ['missing' => [null], 'array' => [['student@example.test']], 'integer' => [123], 'invalid' => ['invalid']];
    }

    public function test_untrusted_peers_cannot_rotate_forwarded_ips_to_evade_login_limits(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeader('X-Forwarded-For', '203.0.113.'.($attempt + 1));
            $this->login()->assertUnprocessable();
        }
        $this->withHeader('X-Forwarded-For', '203.0.113.99');
        $this->assertThrottled($this->login(), 5, 60);
    }

    public function test_localhost_proxy_uses_real_client_ips_for_authentication_buckets(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeader('X-Forwarded-For', '198.51.100.10');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login()->assertUnprocessable();
        }
        $this->assertThrottled($this->login(), 5, 60);
        $this->withHeader('X-Forwarded-For', '198.51.100.11');
        $this->login()->assertUnprocessable();
        // Nginx appending the real peer defeats a client-supplied leftmost address.
        $this->withHeader('X-Forwarded-For', '203.0.113.99, 198.51.100.10');
        $this->assertThrottled($this->login(), 5, 60);
    }

    #[DataProvider('authenticationLimiters')]
    public function test_database_cache_preserves_limits_across_limiter_instances(string $name, string $path, int $limit, int $retryAfter): void
    {
        config(['cache.stores.auth-security' => [
            'driver' => 'database', 'table' => 'cache', 'prefix' => 'auth-security-'.Str::uuid().'-',
        ]]);
        $definition = RateLimiter::limiter($name);
        $replaceLimiter = function () use ($name, $definition): void {
            $limiter = new CacheRateLimiter(Cache::store('auth-security'));
            $limiter->for($name, $definition);
            RateLimiter::swap($limiter);
        };
        $replaceLimiter();
        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->postJson($path, ['email' => 'student@example.test', 'password' => 'wrong-password'])->assertUnprocessable();
        }

        $replaceLimiter();
        $this->assertThrottled($this->postJson($path, ['email' => 'student@example.test']), $limit, $retryAfter);
    }

    public static function authenticationLimiters(): array
    {
        return [
            'login' => ['auth-login', '/api/auth/login', 5, 60],
            'registration' => ['auth-register', '/api/auth/register', 10, 3600],
        ];
    }

    public function test_login_limiter_keys_hash_normalized_email_and_separate_the_two_buckets(): void
    {
        $request = Request::create('/api/auth/login', 'POST', ['email' => ' Student@Example.Test '], [], [], [
            'REMOTE_ADDR' => '198.51.100.10',
        ]);
        $limits = RateLimiter::limiter('auth-login')($request);

        $this->assertSame('auth:login:identity:'.hash('sha256', 'student@example.test').':ip:198.51.100.10', $limits[0]->key);
        $this->assertSame('auth:login:ip:198.51.100.10', $limits[1]->key);
        foreach ($limits as $limit) {
            $this->assertStringNotContainsString('student@example.test', strtolower($limit->key));
        }
    }

    public function test_https_proxy_preserves_cookie_authentication_csrf_logout_and_throttle_behavior(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders([
            'X-Forwarded-For' => '198.51.100.10', 'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'student-ai-planner.duckdns.org', 'X-Forwarded-Port' => '443',
        ]);
        config(['session.secure' => true]);
        $this->startBrowser();
        config(['sanctum.stateful' => ['student-ai-planner.duckdns.org']]);
        $this->withHeader('Origin', 'https://student-ai-planner.duckdns.org');
        $registration = ['name' => 'Student', 'email' => 'student@example.test',
            'password' => 'secure-password', 'password_confirmation' => 'secure-password'];
        $this->browser('POST', '/api/auth/register', $registration, ['X-XSRF-TOKEN' => ''])->assertStatus(419);
        $registered = $this->browser('POST', '/api/auth/register', $registration)->assertCreated();
        $this->assertTrue($registered->getCookie(config('session.cookie'), false)->isSecure());
        $this->browser('GET', '/api/profile')->assertOk()->assertJsonPath('data.id', $registered->json('data.id'));
        $this->browser('POST', '/api/auth/logout')->assertNoContent();
        $this->browser('GET', '/api/profile')->assertUnauthorized();
        $credentials = ['email' => 'student@example.test', 'password' => 'secure-password'];
        $this->browser('POST', '/api/auth/login', $credentials)->assertOk();
        $this->browser('GET', '/api/profile')->assertOk();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->browser('POST', '/api/auth/login', [...$credentials, 'password' => 'wrong-password'])->assertUnprocessable();
        }
        $this->assertThrottled($this->browser('POST', '/api/auth/login', $credentials), 5, 60);
        $this->browser('GET', '/api/profile')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
