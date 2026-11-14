<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\ProfileService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithSpaBrowser;
use Tests\Support\TestDatabase;
use Tests\TestCase;

#[Group('mysql')]
#[Group('security')]
class EmailVerificationTest extends TestCase
{
    use DatabaseTruncation, InteractsWithSpaBrowser;

    private const SEND = '/api/profile/email/verification-notification';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://planner.example.test', 'mail.default' => 'array']);
        Notification::fake();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function path(User $user): string
    {
        $url = app(EmailVerificationService::class)->verificationUrl($user);
        $this->assertStringStartsWith('https://planner.example.test/verify-email?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['verification_path'], array_keys($query));

        return $query['verification_path'];
    }

    public function test_verification_requires_authentication_and_never_mutates_on_get(): void
    {
        $user = User::factory()->unverified()->create();
        $path = $this->path($user);
        $before = $user->fresh()->getAttributes();
        $this->postJson(self::SEND)->assertUnauthorized();
        $this->postJson($path)->assertUnauthorized();
        $this->getJson($path)->assertStatus(405);
        $this->actingAs($user, 'web')->getJson($path)->assertStatus(405);
        $this->assertSame($before, $user->fresh()->getAttributes());
        Notification::assertNothingSent();
    }

    public function test_only_explicit_request_sends_to_current_account_and_client_cannot_choose_recipient(): void
    {
        $identity = $this->registerBrowser();
        $owner = User::findOrFail($identity['id']);
        $this->assertFalse($identity['email_verified']);
        $this->assertNull($owner->email_verified_at);
        Notification::assertNothingSent();
        $foreign = User::factory()->unverified()->create();
        $this->browser('POST', self::SEND, ['email' => $foreign->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        Notification::assertNothingSent();
        $this->travel(1)->minutes();
        $this->browser('POST', self::SEND, ['user_id' => $foreign->id])->assertOk()
            ->assertExactJson(['message' => 'Лист для підтвердження email-адреси надіслано.']);
        Notification::assertSentTo($owner, VerifyEmail::class, function ($notification, $channels, $notifiable) use ($owner): bool {
            $this->assertSame(['mail'], $channels);
            $this->assertSame($owner->email, $notifiable->routeNotificationFor('mail'));
            $this->assertFalse($notifiable->hasVerifiedEmail());

            return true;
        });
        Notification::assertNotSentTo($foreign, VerifyEmail::class);
        Notification::assertCount(1);
        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public function test_resends_are_limited_per_account_per_minute_and_hour(): void
    {
        $owner = User::factory()->unverified()->create();
        $this->actingAs($owner, 'web')->postJson(self::SEND)->assertOk();
        $this->postJson(self::SEND)->assertStatus(429)->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Забагато спроб. Спробуйте ще раз пізніше.');
        Notification::assertCount(1);
        $other = User::factory()->unverified()->create();
        Auth::forgetGuards();
        $this->actingAs($other, 'web')->postJson(self::SEND)->assertOk();
        Auth::forgetGuards();
        $this->actingAs($owner, 'web');
        for ($i = 0; $i < 5; $i++) {
            $this->travel(61)->seconds();
            $this->postJson(self::SEND)->assertOk();
        }
        $this->travel(61)->seconds();
        $this->postJson(self::SEND)->assertStatus(429)->assertHeader('Retry-After');
        Notification::assertSentToTimes($owner, VerifyEmail::class, 6);
        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public function test_verified_account_never_receives_another_verification_email(): void
    {
        $owner = User::factory()->create();
        $before = $owner->fresh()->getAttributes();
        $this->actingAs($owner, 'web')->postJson(self::SEND)->assertOk()
            ->assertExactJson(['message' => 'Email-адресу вже підтверджено.']);
        Notification::assertNothingSent();
        $this->assertSame($before, $owner->fresh()->getAttributes());
    }

    public function test_confirmation_attempts_are_throttled_without_verifying_the_account(): void
    {
        $owner = User::factory()->unverified()->create();
        $path = preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $this->path($owner));
        $this->actingAs($owner, 'web');
        for ($i = 0; $i < 6; $i++) {
            $this->postJson($path)->assertForbidden();
        }
        $this->postJson($path)->assertStatus(429)->assertHeader('Retry-After');
        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public function test_signed_post_marks_verified_once_and_profile_exposes_the_result(): void
    {
        $owner = User::factory()->unverified()->create();
        $path = $this->path($owner);
        Event::fake([Verified::class]);
        $this->actingAs($owner, 'web')->getJson('/api/profile')->assertOk()->assertJsonPath('data.email_verified', false);
        $this->postJson($path)->assertOk()->assertJsonPath('message', 'Email-адресу підтверджено.')
            ->assertJsonPath('data.email_verified', true)->assertJsonPath('data.id', $owner->id);
        $this->assertNotNull($owner->fresh()->email_verified_at);
        $before = $owner->fresh()->getAttributes();
        $this->travel(2)->minutes();
        $this->postJson($path)->assertOk()->assertJsonPath('data.email_verified', true);
        Auth::forgetGuards();
        $this->actingAs($owner->fresh(), 'web')->getJson('/api/profile')->assertOk()->assertJsonPath('data.email_verified', true);
        $this->assertSame($before, $owner->fresh()->getAttributes());
        Event::assertDispatchedTimes(Verified::class, 1);
        Notification::assertNothingSent();
    }

    public static function invalidLinks(): array
    {
        return array_map(fn ($case) => [$case], ['expired', 'signature', 'hash', 'id', 'extra query', 'foreign', 'signed wrong email', 'missing signature']);
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_or_foreign_links_cannot_change_any_account(string $case): void
    {
        $owner = User::factory()->unverified()->create();
        $foreign = User::factory()->unverified()->create();
        $path = $this->path($owner);
        $path = match ($case) {
            'signature' => preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $path),
            'hash' => str_replace(sha1($owner->email), str_repeat('0', 40), $path),
            'id' => str_replace('/verify/'.$owner->id.'/', '/verify/'.$foreign->id.'/', $path),
            'extra query' => $path.'&email=attacker%40example.test',
            'signed wrong email' => URL::temporarySignedRoute('verification.verify', now()->addHour(),
                ['id' => $owner->id, 'hash' => sha1('wrong@example.test')], absolute: false),
            'missing signature' => strtok($path, '?'),
            default => $path,
        };
        if ($case === 'expired') {
            $this->travel(61)->minutes();
        }
        $before = [$owner->fresh()->getAttributes(), $foreign->fresh()->getAttributes()];
        Event::fake([Verified::class]);
        $this->actingAs($case === 'foreign' ? $foreign : $owner, 'web')->postJson($path)->assertForbidden();
        $this->assertSame($before, [$owner->fresh()->getAttributes(), $foreign->fresh()->getAttributes()]);
        Event::assertNotDispatched(Verified::class);
    }

    public function test_email_change_resets_verification_old_link_fails_and_new_link_works(): void
    {
        $owner = User::factory()->create();
        $oldPath = $this->path($owner);
        $this->actingAs($owner, 'web')->patchJson('/api/profile', ['email' => 'changed@example.test', 'email_verified_at' => now()->toISOString()])
            ->assertOk()->assertJsonPath('data.email_verified', false);
        $this->assertNull($owner->fresh()->email_verified_at);
        Notification::assertNothingSent();
        $this->postJson($oldPath)->assertForbidden();
        $this->assertNull($owner->fresh()->email_verified_at);
        $this->postJson(self::SEND)->assertOk();
        Notification::assertSentTo($owner, VerifyEmail::class, fn ($notification, $channels, $notifiable) => $notifiable->email === 'changed@example.test');
        $this->postJson($this->path($owner->fresh()))->assertOk()->assertJsonPath('data.email_verified', true);
    }

    public static function nonEmailChanges(): array
    {
        return [[['name' => 'Нове ім’я']], [['timezone' => 'Europe/Kyiv']], [[]], [['email' => 'same@example.test']]];
    }

    #[DataProvider('nonEmailChanges')]
    public function test_other_changes_and_same_email_preserve_verification(array $changes): void
    {
        $owner = User::factory()->create(['email' => 'same@example.test']);
        $verifiedAt = $owner->getRawOriginal('email_verified_at');
        $this->actingAs($owner, 'web')->patchJson('/api/profile', $changes)->assertOk()->assertJsonPath('data.email_verified', true);
        $this->assertSame($verifiedAt, $owner->fresh()->getRawOriginal('email_verified_at'));
        Notification::assertNothingSent();
    }

    public function test_confirmation_reloads_email_instead_of_trusting_stale_authenticated_model(): void
    {
        $owner = User::factory()->unverified()->create();
        $path = $this->path($owner);
        $this->actingAs($owner, 'web');
        app(ProfileService::class)->update($owner, ['email' => 'changed-after-auth@example.test']);
        $this->assertNotSame($owner->email, $owner->fresh()->email);
        $before = $owner->fresh()->getAttributes();
        $this->postJson($path)->assertForbidden();
        $this->assertSame($before, $owner->fresh()->getAttributes());
    }

    public static function writeOrders(): array
    {
        return [['email-first'], ['verification-first']];
    }

    #[DataProvider('writeOrders')]
    public function test_concurrent_email_change_and_verification_serialize_on_the_current_user(string $order): void
    {
        $owner = User::factory()->unverified()->create();
        $path = $this->path($owner);
        $input = ['owner_id' => $owner->id, 'path' => $path, 'action' => $order === 'email-first' ? 'verify' : 'change'];
        $worker = new Process([PHP_BINARY, base_path('tests/Support/email_verification_worker.php')], base_path(),
            ['PLANNER_TEST_DATABASE' => TestDatabase::name()], json_encode($input, JSON_THROW_ON_ERROR), timeout: 20);
        DB::beginTransaction();
        try {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, '"ready":true')));
            $ready = json_decode(strtok($worker->getOutput(), "\n"), true, flags: JSON_THROW_ON_ERROR);
            // Prove the independent connection is waiting for our actual MySQL row lock.
            $blocked = false;
            $deadline = microtime(true) + 5;
            do {
                $blocked = (bool) DB::selectOne('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w
                    JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?', [$ready['connection_id']])->n;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            $this->assertTrue($blocked, 'The competing write must wait for the owner lock.');
            if ($order === 'email-first') {
                app(ProfileService::class)->update($owner, ['email' => 'concurrent@example.test']);
            } else {
                $request = Request::create($path, 'POST');
                $route = app('router')->getRoutes()->match($request);
                $request->setRouteResolver(fn () => $route);
                app(EmailVerificationService::class)->confirm($owner, $request);
            }
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getOutput());
            $lines = explode("\n", trim($worker->getOutput()));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($order === 'email-first' ? 403 : 200, $result['status']);
            $this->assertSame('concurrent@example.test', $owner->fresh()->email);
            $this->assertNull($owner->fresh()->email_verified_at);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker->stop();
        }
    }

    public function test_cookie_flow_requires_csrf_and_explicit_post_without_restricting_unverified_access(): void
    {
        $identity = $this->registerBrowser();
        $owner = User::findOrFail($identity['id']);
        $path = $this->path($owner);
        $this->browser('GET', '/api/profile')->assertOk()->assertJsonPath('data.email_verified', false);
        $this->browser('GET', '/api/tasks')->assertOk();
        $this->browser('POST', self::SEND, [], ['X-XSRF-TOKEN' => ''])->assertStatus(419);
        Notification::assertNothingSent();
        $this->browser('POST', self::SEND)->assertOk();
        $this->browser('GET', $path)->assertStatus(405);
        $this->browser('POST', $path, [], ['X-XSRF-TOKEN' => ''])->assertStatus(419);
        $this->assertNull($owner->fresh()->email_verified_at);
        $this->browser('POST', $path)->assertOk()->assertJsonPath('data.email_verified', true);
        $this->browser('GET', '/api/profile')->assertOk()->assertJsonPath('data.email_verified', true);
    }

    public function test_real_notification_renders_ukrainian_html_and_text_to_current_address_in_memory_only(): void
    {
        Notification::swap(new ChannelManager($this->app));
        $owner = User::factory()->unverified()->create(['email' => 'student@example.test']);
        $this->actingAs($owner, 'web')->postJson(self::SEND)->assertOk();
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->sole()->getOriginalMessage();
        $this->assertSame('student@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame('Підтвердження email-адреси', $message->getSubject());
        $this->assertStringContainsString('<html lang="uk">', $message->getHtmlBody());
        $this->assertStringContainsString('натисніть кнопку підтвердження', $message->getTextBody());
        preg_match('~https://planner.example.test/verify-email\?[^\s]+~', $message->getTextBody(), $matches);
        parse_str(parse_url($matches[0], PHP_URL_QUERY), $query);
        $this->assertStringNotContainsString($owner->email, $matches[0]);
        $this->postJson($query['verification_path'])->assertOk()->assertJsonPath('data.email_verified', true);
    }

    public function test_mail_failure_has_no_sensitive_debug_details_or_verification_mutation(): void
    {
        $owner = User::factory()->unverified()->create();
        config(['app.debug' => true]);
        Log::spy();
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP secret-password SQL private data'));
        $this->actingAs($owner, 'web')->postJson(self::SEND)->assertStatus(503)
            ->assertExactJson(['message' => 'Підтвердження email-адреси тимчасово недоступне. Спробуйте пізніше.']);
        $this->assertNull($owner->fresh()->email_verified_at);
        Log::shouldHaveReceived('warning')->once()->with('email_verification.failure');
        Log::shouldNotHaveReceived('error');
    }

    public function test_unsafe_frontend_configuration_fails_closed_without_sending_mail(): void
    {
        Notification::swap(new ChannelManager($this->app));
        config(['app.frontend_url' => 'https://planner.example.test/?redirect=unsafe']);
        $owner = User::factory()->unverified()->create();
        $this->actingAs($owner, 'web')->postJson(self::SEND)->assertStatus(503);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
        $this->assertNull($owner->fresh()->email_verified_at);
    }
}
