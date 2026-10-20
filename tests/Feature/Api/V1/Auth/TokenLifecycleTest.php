<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API-AUTH-003 logout, API-AUTH-004 refresh, 60-day expiry, SEC-001/002/019.
 */
class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('protectedRoutes')]
    public function test_protected_routes_require_a_token(string $method, string $uri): void
    {
        $this->assertApiError($this->json($method, $uri), 401, 'UNAUTHENTICATED');
    }

    public static function protectedRoutes(): array
    {
        return [
            ['POST', '/api/v1/auth/logout'],
            ['POST', '/api/v1/auth/refresh'],
            ['GET', '/api/v1/me'],
            ['PATCH', '/api/v1/me'],
            ['DELETE', '/api/v1/me'],
            ['PUT', '/api/v1/me/password'],
        ];
    }

    public function test_token_ids_are_ulids_so_tokens_do_not_reveal_issue_counts(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        [$id] = explode('|', $token, 2);
        $this->assertTrue(Str::isUlid($id));
        $this->assertInstanceOf(PersonalAccessToken::class, PersonalAccessToken::findToken($token));
    }

    public function test_tokens_expire_after_60_days(): void
    {
        $issuedAt = now()->startOfSecond();
        $this->travelTo($issuedAt);
        $token = $this->tokenFor(User::factory()->create());

        $this->travelTo($issuedAt->copy()->addDays(60)->subSecond());
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->resetAuth();
        $this->travelTo($issuedAt->copy()->addDays(60)->addSecond());
        $this->assertApiError($this->withToken($token)->getJson('/api/v1/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_logout_revokes_only_the_current_token_by_default(): void
    {
        $user = User::factory()->create();
        $phone = $this->tokenFor($user, 'phone');
        $tablet = $this->tokenFor($user, 'tablet');

        $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->resetAuth();
        $this->assertApiError($this->withToken($phone)->getJson('/api/v1/me'), 401, 'UNAUTHENTICATED');
        $this->resetAuth();
        $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
    }

    public function test_logout_all_devices_revokes_every_token(): void
    {
        $user = User::factory()->create();
        $phone = $this->tokenFor($user, 'phone');
        $tablet = $this->tokenFor($user, 'tablet');

        $this->withToken($phone)->postJson('/api/v1/auth/logout', ['all_devices' => true])->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
        $this->resetAuth();
        $this->assertApiError($this->withToken($tablet)->getJson('/api/v1/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_logout_rejects_non_boolean_all_devices(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->assertApiError(
            $this->withToken($token)->postJson('/api/v1/auth/logout', ['all_devices' => 'maybe']),
            422,
            'VALIDATION_FAILED',
        );
    }

    public function test_logout_does_not_touch_other_users_tokens(): void
    {
        $other = User::factory()->create();
        $otherToken = $this->tokenFor($other);

        $this->withToken($this->tokenFor(User::factory()->create()))
            ->postJson('/api/v1/auth/logout', ['all_devices' => true])
            ->assertNoContent();

        $this->resetAuth();
        $this->withToken($otherToken)->getJson('/api/v1/me')->assertOk();
    }

    public function test_refresh_rotates_the_token_and_revokes_the_old_one(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        $old = $this->tokenFor($user, 'phone');

        $this->travel(31)->days();

        $response = $this->withToken($old)->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->assertExactJsonStructure(['data' => ['token' => ['access_token', 'token_type', 'expires_at']]])
            ->assertJsonPath('data.token.token_type', 'Bearer')
            ->assertJsonPath('data.token.expires_at', now()->addDays(60)->utc()->format('Y-m-d\TH:i:s\Z'));

        $new = $response->json('data.token.access_token');
        $this->assertNotSame($old, $new);
        $this->assertSame('phone', $user->tokens()->sole()->name);

        $this->resetAuth();
        $this->assertApiError($this->withToken($old)->getJson('/api/v1/me'), 401, 'UNAUTHENTICATED');
        $this->resetAuth();
        $this->withToken($new)->getJson('/api/v1/me')->assertOk();
    }

    public function test_refresh_keeps_the_users_other_tokens(): void
    {
        $user = User::factory()->create();
        $phone = $this->tokenFor($user, 'phone');
        $tablet = $this->tokenFor($user, 'tablet');

        $this->withToken($phone)->postJson('/api/v1/auth/refresh')->assertOk();

        $this->resetAuth();
        $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
    }

    public function test_expired_token_cannot_be_refreshed(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->travel(61)->days();

        $this->assertApiError($this->withToken($token)->postJson('/api/v1/auth/refresh'), 401, 'UNAUTHENTICATED');
    }

    public function test_expired_token_cleanup_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('sanctum:prune-expired --hours=24')
            ->assertSuccessful();
    }
}
