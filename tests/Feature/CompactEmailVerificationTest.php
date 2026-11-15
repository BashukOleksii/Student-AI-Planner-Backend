<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithSpaBrowser;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactEmailVerificationTest extends TestCase
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

    public function test_explicit_request_sends_one_verification_notification_to_the_current_account(): void
    {
        $identity = $this->registerBrowser();
        $owner = User::findOrFail($identity['id']);
        $this->assertFalse($identity['email_verified']);
        $this->assertNull($owner->email_verified_at);
        Notification::assertNothingSent();
        $this->browser('POST', self::SEND)->assertOk()->assertExactJson(['message' => 'Лист для підтвердження email-адреси надіслано.']);
        Notification::assertSentTo($owner, VerifyEmail::class, function ($notification, $channels, $notifiable) use ($owner): bool {
            $this->assertSame(['mail'], $channels);
            $this->assertSame($owner->email, $notifiable->routeNotificationFor('mail'));
            $this->assertFalse($notifiable->hasVerifiedEmail());

            return true;
        });
        Notification::assertCount(1);
        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public function test_resends_are_limited_per_account_per_minute_and_hour(): void
    {
        $owner = User::factory()->unverified()->create();
        $this->actingAs($owner, 'web')->postJson(self::SEND)->assertOk();
        $this->postJson(self::SEND)->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('message', 'Забагато спроб. Спробуйте ще раз пізніше.');
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
        $this->actingAs($owner, 'web')->postJson(self::SEND)->assertOk()->assertExactJson(['message' => 'Email-адресу вже підтверджено.']);
        Notification::assertNothingSent();
        $this->assertSame($before, $owner->fresh()->getAttributes());
    }

    public function test_real_notification_renders_ukrainian_html_and_text_to_current_address_in_memory_only(): void
    {
        Notification::swap(new ChannelManager($this->app));
        $owner = User::factory()->unverified()->create(['email' => 'student@example.test']);
        $this->actingAs($owner, 'web')->postJson(self::SEND)->assertOk();
        /** @var Mailer $mailer */
        $mailer = Mail::mailer();
        /** @var ArrayTransport $transport */
        $transport = $mailer->getSymfonyTransport();
        $messages = $transport->messages();
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

    public function test_signed_post_marks_verified_once_and_profile_exposes_the_result(): void
    {
        $owner = User::factory()->unverified()->create();
        $path = $this->path($owner);
        Event::fake([Verified::class]);
        $this->actingAs($owner, 'web')->getJson('/api/profile')->assertOk()->assertJsonPath('data.email_verified', false);
        $this->postJson($path)->assertOk()->assertJsonPath('message', 'Email-адресу підтверджено.')->assertJsonPath('data.email_verified', true)->assertJsonPath('data.id', $owner->id);
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

    public function test_email_change_resets_verification_old_link_fails_and_new_link_works(): void
    {
        $owner = User::factory()->create();
        $oldPath = $this->path($owner);
        $this->actingAs($owner, 'web')->patchJson('/api/profile', ['email' => 'changed@example.test'])->assertOk()->assertJsonPath('data.email_verified', false);
        $this->assertNull($owner->fresh()->email_verified_at);
        Notification::assertNothingSent();
        $this->postJson($oldPath)->assertForbidden();
        $this->assertNull($owner->fresh()->email_verified_at);
        $this->postJson(self::SEND)->assertOk();
        Notification::assertSentTo($owner, VerifyEmail::class, fn ($notification, $channels, $notifiable) => $notifiable->email === 'changed@example.test');
        $this->postJson($this->path($owner->fresh()))->assertOk()->assertJsonPath('data.email_verified', true);
    }
}
