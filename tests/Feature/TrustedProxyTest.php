<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_trusted-proxy-test', function (Request $request) {
            return response()->json([
                'secure' => $request->secure(),
                'scheme' => $request->getScheme(),
                'host' => $request->getHost(),
                'port' => $request->getPort(),
                'ip' => $request->ip(),
                'url' => url('/trusted-proxy-target'),
                'route' => route('login'),
            ]);
        });
    }

    public function test_trusted_immediate_proxy_supplies_public_request_context(): void
    {
        $response = $this
            ->withServerVariables([
                'REMOTE_ADDR' => '172.20.0.5',
                'SERVER_PORT' => '80',
            ])
            ->withHeaders([
                'Host' => 'docker-nginx',
                'X-Forwarded-For' => '203.0.113.25',
                'X-Forwarded-Host' => 'p3.local.test',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/_trusted-proxy-test');

        $response->assertOk()->assertExactJson([
            'secure' => true,
            'scheme' => 'https',
            'host' => 'p3.local.test',
            'port' => 443,
            'ip' => '203.0.113.25',
            'url' => 'https://p3.local.test/trusted-proxy-target',
            'route' => 'https://p3.local.test/login',
        ]);
    }

    public function test_direct_request_keeps_socket_context_when_excluded_forwarded_header_families_are_supplied(): void
    {
        $response = $this
            ->withServerVariables([
                'REMOTE_ADDR' => '198.51.100.40',
                'SERVER_PORT' => '8080',
            ])
            ->withHeaders([
                'Forwarded' => 'for=203.0.113.99;host=spoofed.example;proto=https',
                'X-Forwarded-Prefix' => '/spoofed',
            ])
            ->get('http://direct.local.test:8080/_trusted-proxy-test');

        $response->assertOk()->assertExactJson([
            'secure' => false,
            'scheme' => 'http',
            'host' => 'direct.local.test',
            'port' => 8080,
            'ip' => '198.51.100.40',
            'url' => 'http://direct.local.test:8080/trusted-proxy-target',
            'route' => 'http://direct.local.test:8080/login',
        ]);
    }

    public function test_production_session_cookie_is_secure_http_only_and_same_site_lax(): void
    {
        config([
            'session.driver' => 'file',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);
        $this->app['session']->forgetDrivers();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '172.20.0.5'])
            ->withHeaders([
                'X-Forwarded-Host' => 'p3.local.test',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/language/ms');

        $sessionCookie = collect($response->baseResponse->headers->getCookies())
            ->first(fn ($cookie): bool => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($sessionCookie);
        $this->assertTrue($sessionCookie->isSecure());
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertSame('lax', $sessionCookie->getSameSite());
    }
}
