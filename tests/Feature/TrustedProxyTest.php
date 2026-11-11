<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mysql')]
class TrustedProxyTest extends TestCase
{
    private const CONTEXT_URL = 'http://internal.test:8080/_testing/proxy-context';

    protected function setUp(): void
    {
        parent::setUp();

        // Test-only endpoint observes the real global middleware stack.
        Route::get('/_testing/proxy-context', fn (Request $request) => response()->json([
            'ip' => $request->ip(), 'secure' => $request->isSecure(), 'scheme' => $request->getScheme(),
            'host' => $request->getHost(), 'port' => $request->getPort(),
        ]));
        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'off', 'SERVER_PORT' => '8080',
        ]);
        $this->withHeaders([
            'Host' => 'internal.test:8080', 'X-Forwarded-For' => '198.51.100.10',
            'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'student-ai-planner.duckdns.org',
            'X-Forwarded-Port' => '443',
        ]);
    }

    public function test_localhost_proxy_supplies_real_client_ip_https_host_and_port(): void
    {
        $this->getJson(self::CONTEXT_URL)->assertOk()->assertExactJson([
            'ip' => '198.51.100.10', 'secure' => true, 'scheme' => 'https',
            'host' => 'student-ai-planner.duckdns.org', 'port' => 443,
        ]);
        $this->withHeader('X-Forwarded-Port', '8443')->getJson(self::CONTEXT_URL)
            ->assertOk()->assertJsonPath('port', 8443);
    }

    #[DataProvider('untrustedPeers')]
    public function test_untrusted_peers_cannot_spoof_ip_scheme_host_or_port(string $peer): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $peer]);
        $this->getJson(self::CONTEXT_URL)->assertOk()->assertExactJson([
            'ip' => $peer, 'secure' => false, 'scheme' => 'http', 'host' => 'internal.test', 'port' => 8080,
        ]);
    }

    public static function untrustedPeers(): array
    {
        return ['public' => ['198.51.100.20'], 'private network' => ['10.0.0.1'],
            'other loopback' => ['127.0.0.2'], 'IPv6 loopback' => ['::1']];
    }

    public function test_untrusted_forwarded_ip_chain_uses_the_nearest_untrusted_peer(): void
    {
        $this->withHeader('X-Forwarded-For', '203.0.113.99, 198.51.100.10, 127.0.0.1')
            ->getJson(self::CONTEXT_URL)->assertOk()->assertJsonPath('ip', '198.51.100.10');
    }

    public function test_localhost_without_forwarded_headers_keeps_direct_request_properties(): void
    {
        foreach (['X-Forwarded-For', 'X-Forwarded-Proto', 'X-Forwarded-Host', 'X-Forwarded-Port'] as $header) {
            $this->withoutHeader($header);
        }
        $this->withHeader('Forwarded', 'for=203.0.113.99;proto=https;host=spoofed.test');
        $this->getJson(self::CONTEXT_URL)->assertOk()->assertExactJson([
            'ip' => '127.0.0.1', 'secure' => false, 'scheme' => 'http', 'host' => 'internal.test', 'port' => 8080,
        ]);
    }
}
