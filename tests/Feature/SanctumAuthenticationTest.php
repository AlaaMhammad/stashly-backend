<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 0 wiring check: Sanctum bearer tokens authenticate against the
 * auth:sanctum guard. Uses a test-only route so no contract endpoint is invented.
 */
class SanctumAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->get('/_test/sanctum-guard', function (Request $request) {
            return ['authenticated_id' => $request->user()->getKey()];
        });
    }

    public function test_request_without_token_is_rejected(): void
    {
        $this->getJson('/_test/sanctum-guard')->assertUnauthorized();
    }

    public function test_request_with_valid_bearer_token_is_authenticated(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phase-0-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/_test/sanctum-guard')
            ->assertOk()
            ->assertExactJson(['authenticated_id' => $user->getKey()]);
    }

    public function test_request_with_revoked_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $newToken = $user->createToken('phase-0-test');
        $newToken->accessToken->delete();

        $this->withToken($newToken->plainTextToken)
            ->getJson('/_test/sanctum-guard')
            ->assertUnauthorized();
    }
}
