<?php

namespace App\Providers;

use App\Policies\DatabaseNotificationPolicy;
use App\Services\AI\AgentLimits;
use App\Services\AI\LlmProvider;
use App\Services\AI\OpenAiResponsesProvider;
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
        $this->app->bind(LlmProvider::class, OpenAiResponsesProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(DatabaseNotification::class, DatabaseNotificationPolicy::class);
        RateLimiter::for('ai-messages', fn (Request $request) => Limit::perMinute(app(AgentLimits::class)->get('requests_per_minute'))
            ->by((string) $request->user()->id));
    }
}
