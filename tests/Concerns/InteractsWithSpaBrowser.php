<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

/** A cookie-only browser: real stateful middleware, encrypted cookies and enabled CSRF. */
trait InteractsWithSpaBrowser
{
    private array $browserCookies = [];

    private function startBrowser(): void
    {
        config(['sanctum.stateful' => ['localhost:5173'], 'cors.allowed_origins' => ['http://localhost:5173'],
            'session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
        $this->app['env'] = 'local'; // Laravel otherwise disables CSRF in tests; DB guard already ran.
        $this->withHeader('Origin', 'http://localhost:5173')->withCredentials();
        $this->beforeApplicationDestroyed(function (): void {
            $this->app['env'] = 'testing';
        });
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
    }

    private function browser(string $method, string $url, array $body = [], array $headers = []): TestResponse
    {
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->withUnencryptedCookies($this->browserCookies);
        $headers = ['X-XSRF-TOKEN' => urldecode($this->browserCookies['XSRF-TOKEN'] ?? ''), ...$headers];
        $response = $this->json($method, $url, $body, $headers, JSON_PRESERVE_ZERO_FRACTION | ($body === [] ? JSON_FORCE_OBJECT : 0));
        foreach ($response->headers->getCookies() as $cookie) {
            $this->browserCookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function registerBrowser(): array
    {
        $this->startBrowser();

        return $this->browser('POST', '/api/auth/register', ['name' => 'Integration Student',
            'email' => 'h4-'.bin2hex(random_bytes(8)).'@example.test', 'password' => 'integration-password',
            'password_confirmation' => 'integration-password', 'timezone' => 'Europe/Kyiv'])
            ->assertCreated()->json('data');
    }
}
