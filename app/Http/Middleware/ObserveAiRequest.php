<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ObserveAiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/ai/*')) {
            return $next($request);
        }
        $started = microtime(true);
        $response = $next($request);
        $route = $request->route()?->getName();

        $allowed = ['ai.conversations.store', 'ai.conversations.index', 'ai.conversations.show',
            'ai.conversations.messages', 'ai.conversations.send', 'ai.conversations.actions.index',
            'ai.conversations.actions.show', 'ai.conversations.actions.confirm', 'ai.conversations.actions.reject'];
        try {
            Log::info('ai.request', ['operation' => in_array($route, $allowed, true) ? $route : 'unknown',
                'status' => $response->getStatusCode(), 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
        } catch (Throwable) {
        }

        return $response;
    }
}
