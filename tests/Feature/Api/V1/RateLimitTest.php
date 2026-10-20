<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Named limiters from the contract "Rate limits" row. The endpoints that will
 * use them arrive in later phases, so test-only routes exercise them here.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/_test')->group(function () {
            Route::post('/login', fn () => ['ok' => true])->middleware('throttle:login');
            Route::post('/register', fn () => ['ok' => true])->middleware('throttle:register');
            Route::post('/forgot', fn () => ['ok' => true])->middleware('throttle:forgot-password');
            Route::middleware('auth:sanctum')->group(function () {
                Route::get('/me', fn () => ['ok' => true]);
                Route::post('/preview', fn () => ['ok' => true])->middleware('throttle:link-preview');
                Route::post('/save', fn () => ['ok' => true])->middleware('throttle:link-save');
                Route::get('/export', fn () => ['ok' => true])->middleware('throttle:export');
            });
        });
    }

    public function test_limits_match_the_contract(): void
    {
        $this->assertSame([
            'api_per_minute' => 120,
            'login_per_minute' => 5,
            'register_per_hour' => 10,
            'forgot_password_per_hour' => 3,
            'link_preview_per_minute' => 20,
            'link_save_per_minute' => 60,
            'export_per_ten_minutes' => 1,
            'metadata_refresh_per_link_per_minute' => 1,
            'metadata_refresh_per_minute' => 5,
        ], config('stashly.rate_limits'));
    }

    public function test_default_api_limit_is_120_per_minute_with_retry_after(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/config')->assertOk();
        }

        $this->assertRateLimited($this->getJson('/api/v1/config'));
    }

    public function test_default_api_limit_is_per_user_for_authenticated_requests(): void
    {
        config(['stashly.rate_limits.api_per_minute' => 2]);
        [$alice, $bob] = User::factory()->count(2)->create();

        Sanctum::actingAs($alice);
        $this->getJson('/api/v1/_test/me')->assertOk();
        $this->getJson('/api/v1/_test/me')->assertOk();
        $this->assertRateLimited($this->getJson('/api/v1/_test/me'));

        Sanctum::actingAs($bob);
        $this->getJson('/api/v1/_test/me')->assertOk();
    }

    public function test_login_is_limited_to_5_per_minute_by_email_and_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/_test/login', ['email' => 'a@example.test'])->assertOk();
        }

        $this->assertRateLimited($this->postJson('/api/v1/_test/login', ['email' => 'a@example.test']));
        // Same address normalized (case, surrounding spaces) shares the bucket.
        $this->assertRateLimited($this->postJson('/api/v1/_test/login', ['email' => ' A@Example.TEST ']));

        $this->postJson('/api/v1/_test/login', ['email' => 'b@example.test'])->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/v1/_test/login', ['email' => 'a@example.test'])
            ->assertOk();
    }

    public function test_non_string_email_does_not_crash_the_limiter(): void
    {
        $this->postJson('/api/v1/_test/login', ['email' => ['x' => 'y']])->assertOk();
    }

    public function test_register_is_limited_to_10_per_hour_by_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/_test/register')->assertOk();
        }

        $response = $this->assertRateLimited($this->postJson('/api/v1/_test/register'));
        $this->assertGreaterThan(60, (int) $response->headers->get('Retry-After'));

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/v1/_test/register')
            ->assertOk();
    }

    public function test_forgot_password_is_limited_to_3_per_hour_by_email_across_ips(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])
                ->postJson('/api/v1/_test/forgot', ['email' => 'a@example.test'])
                ->assertOk();
        }

        $this->assertRateLimited(
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
                ->postJson('/api/v1/_test/forgot', ['email' => 'a@example.test'])
        );

        $this->postJson('/api/v1/_test/forgot', ['email' => 'b@example.test'])->assertOk();
    }

    public function test_link_preview_is_limited_to_20_per_minute_per_user(): void
    {
        $this->assertUserLimit('/api/v1/_test/preview', 20);
    }

    public function test_link_save_is_limited_to_60_per_minute_per_user(): void
    {
        $this->assertUserLimit('/api/v1/_test/save', 60);
    }

    public function test_export_is_limited_to_1_per_10_minutes_per_user(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/_test/export')->assertOk();

        $response = $this->assertRateLimited($this->getJson('/api/v1/_test/export'));
        $this->assertGreaterThan(540, (int) $response->headers->get('Retry-After'));
    }

    private function assertUserLimit(string $uri, int $max): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();

        Sanctum::actingAs($alice);
        for ($i = 0; $i < $max; $i++) {
            $this->postJson($uri)->assertOk();
        }
        $this->assertRateLimited($this->postJson($uri));

        Sanctum::actingAs($bob);
        $this->postJson($uri)->assertOk();
    }

    private function assertRateLimited(TestResponse $response): TestResponse
    {
        $this->assertApiError($response, 429, 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));

        return $response;
    }
}
