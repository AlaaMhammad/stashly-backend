<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contract: ULIDs for all ids; timestamps ISO 8601 UTC (2026-10-16T09:30:12Z).
 */
class UlidAndDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_get_ulid_primary_keys(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Str::isUlid($user->getKey()));
        $this->assertSame($user->getKey(), User::query()->sole()->getKey());
    }

    public function test_sanctum_tokens_reference_the_ulid_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('device')->accessToken;

        $this->assertSame($user->getKey(), $token->tokenable_id);
        $this->assertTrue($token->tokenable->is($user));
    }

    public function test_model_dates_serialize_as_utc_without_fractional_seconds(): void
    {
        $this->travelTo('2026-10-16 09:30:12.123456');

        $user = User::factory()->create();

        $this->assertSame('2026-10-16T09:30:12Z', $user->toArray()['created_at']);
    }
}
