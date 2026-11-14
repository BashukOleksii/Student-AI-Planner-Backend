<?php

namespace App\Providers;

use App\Exceptions\AiAgentException;
use App\Models\AcademicPeriod;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\ScheduleDateException;
use App\Models\ScheduleDateExceptionLesson;
use App\Models\SemesterSchedule;
use App\Models\SemesterScheduleSuppressedSlot;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use App\Observers\PlanningSourceObserver;
use App\Observers\StudySessionReminderObserver;
use App\Policies\DatabaseNotificationPolicy;
use App\Services\AI\AgentLimits;
use App\Services\AI\GeminiGenerateContentProvider;
use App\Services\AI\LlmProvider;
use App\Services\AI\OpenAiResponsesProvider;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\PasswordManagementService;
use App\Services\Planning\RecoveryCheckTriggerService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RecoveryCheckTriggerService::class);
        $this->app->bind(AgentLimits::class, fn () => new AgentLimits(config('ai')));
        $this->app->bind(LlmProvider::class, fn ($app) => match (config('ai.provider')) {
            'gemini' => $app->make(GeminiGenerateContentProvider::class),
            'openai' => $app->make(OpenAiResponsesProvider::class),
            default => throw new AiAgentException('ai_unavailable'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        StudySession::observe(StudySessionReminderObserver::class);
        foreach ([StudyAvailabilityWindow::class, PlanningPreference::class,
            SemesterSchedule::class, WeeklyLessonRule::class,
            ScheduleDateException::class, ScheduleDateExceptionLesson::class,
            SemesterScheduleSuppressedSlot::class, AcademicPeriod::class,
            Lesson::class, Task::class, Subtask::class,
            StudySession::class, User::class] as $source) {
            $source::observe(PlanningSourceObserver::class);
        }
        ResetPassword::createUrlUsing(fn ($user, $token) => app(PasswordManagementService::class)->resetUrl($user, $token));
        VerifyEmail::createUrlUsing(fn ($user) => app(EmailVerificationService::class)->verificationUrl($user));
        VerifyEmail::toMailUsing(fn ($user, $url) => (new MailMessage)->subject('Підтвердження email-адреси')
            ->view(['html' => 'mail.verify-email', 'text' => 'mail.verify-email-text'], ['verificationUrl' => $url]));
        $verificationThrottle = fn ($request, $headers) => response()->json([
            'message' => 'Забагато спроб. Спробуйте ще раз пізніше.',
        ], 429, $headers);
        RateLimiter::for('email-verification-send', fn (Request $request) => [
            Limit::perMinute(1)->by('email:send:minute:'.$request->user()->id)->response($verificationThrottle),
            Limit::perHour(6)->by('email:send:hour:'.$request->user()->id)->response($verificationThrottle),
        ]);
        RateLimiter::for('email-verification-confirm', fn (Request $request) => Limit::perMinute(6)
            ->by('email:confirm:'.$request->user()->id)->response($verificationThrottle));
        RateLimiter::for('auth-login', function (Request $request): array {
            $email = $request->input('email');
            $identity = hash('sha256', is_string($email) ? mb_strtolower(trim($email)) : '');

            return [
                Limit::perMinute(5)->by('auth:login:identity:'.$identity.':ip:'.$request->ip()),
                Limit::perMinute(30)->by('auth:login:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(10)->by('auth:register:ip:'.$request->ip()));
        RateLimiter::for('password-forgot', fn (Request $request) => Limit::perMinute(5)->by('forgot:'.$request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)->by('reset:'.$request->ip()));
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by('change:'.$request->user()->id));
        Gate::policy(DatabaseNotification::class, DatabaseNotificationPolicy::class);
        RateLimiter::for('ai-messages', fn (Request $request) => Limit::perMinute(app(AgentLimits::class)->get('requests_per_minute'))
            ->by((string) $request->user()->id));
    }
}
