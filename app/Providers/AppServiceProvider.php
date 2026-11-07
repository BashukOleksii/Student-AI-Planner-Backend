<?php

namespace App\Providers;

use App\Exceptions\AiAgentException;
use App\Policies\DatabaseNotificationPolicy;
use App\Services\AI\AgentLimits;
use App\Services\AI\GeminiGenerateContentProvider;
use App\Services\AI\LlmProvider;
use App\Services\AI\OpenAiResponsesProvider;
use App\Services\Auth\PasswordManagementService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
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
        ResetPassword::createUrlUsing(fn ($user, $token) => app(PasswordManagementService::class)->resetUrl($user, $token));
        RateLimiter::for('password-forgot', fn (Request $request) => Limit::perMinute(5)->by('forgot:'.$request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)->by('reset:'.$request->ip()));
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by('change:'.$request->user()->id));
        Gate::policy(DatabaseNotification::class, DatabaseNotificationPolicy::class);
        RateLimiter::for('ai-messages', fn (Request $request) => Limit::perMinute(app(AgentLimits::class)->get('requests_per_minute'))
            ->by((string) $request->user()->id));
    }
}
