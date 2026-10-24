<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Response headers, CORS, HTTPS and debug-mode behavior (security-plan.md
 * "Deployment"; SEC-016).
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_are_not_cacheable_and_not_sniffable(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach (['/api/v1/health', '/api/v1/me', '/api/v1/links', '/api/v1/home'] as $uri) {
            $response = $this->getJson($uri)->assertOk();

            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'), $uri);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $uri);
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'), $uri);
        }
    }

    public function test_error_responses_from_matched_routes_carry_the_headers_too(): void
    {
        $response = $this->getJson('/api/v1/me');

        $this->assertApiError($response, 401, 'UNAUTHENTICATED');
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_config_keeps_its_explicit_five_minute_public_cache(): void
    {
        $cache = $this->getJson('/api/v1/config')->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=300', $cache);
        $this->assertStringNotContainsString('no-store', $cache);
    }

    public function test_hsts_is_sent_only_over_https_in_production(): void
    {
        $this->getJson('/api/v1/health')->assertHeaderMissing('Strict-Transport-Security');

        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('https://localhost/api/v1/health')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->getJson('http://localhost/api/v1/health')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_cors_is_not_enabled_so_browsers_cannot_read_the_api_cross_origin(): void
    {
        $this->getJson('/api/v1/config', ['Origin' => 'https://evil.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        $preflight = $this->call('OPTIONS', '/api/v1/links', server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertNull($preflight->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_unexpected_errors_are_generic_with_debug_on_and_off(): void
    {
        Route::middleware('api')->prefix('api/v1/_test')->get('/crash', fn () => throw new RuntimeException('SELECT * FROM users WHERE password = x'));

        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);
            $response = $this->getJson('/api/v1/_test/crash');

            $this->assertApiError($response, 500, 'SERVER_ERROR');
            $this->assertStringNotContainsString('SELECT', $response->getContent());
            $this->assertStringNotContainsString('trace', strtolower($response->getContent()));
            $this->assertStringNotContainsString('vendor', $response->getContent());
        }
    }

    public function test_the_reset_password_page_is_not_cacheable_or_frameable(): void
    {
        $response = $this->get('/reset-password?email=a%40example.test');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_client_ip_comes_from_forwarded_headers_only_for_trusted_proxies(): void
    {
        Route::middleware('api')->prefix('api/v1/_test')->get('/ip', fn (Request $r) => ['ip' => $r->ip()]);

        $untrusted = $this->withHeader('X-Forwarded-For', '6.6.6.6')->getJson('/api/v1/_test/ip');

        $this->assertSame('127.0.0.1', $untrusted->json('ip'), 'A spoofed X-Forwarded-For must not change the rate-limit key.');
    }
}
