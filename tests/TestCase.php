<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\TokenIssuer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Auth guards cache the resolved user for the life of the app instance,
     * which spans every request in a test. Call this between requests so a
     * revoked or expired token is re-checked, as it would be in production.
     */
    protected function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * Issue a real bearer token the way the API does (60-day expiry).
     */
    protected function tokenFor(User $user, string $device = 'test device'): string
    {
        return app(TokenIssuer::class)->issue($user, $device)->plainTextToken;
    }

    /**
     * Assert the contract error envelope: { "error": { "code", "message", "details", "request_id" } }.
     */
    protected function assertApiError(TestResponse $response, int $status, string $code): TestResponse
    {
        $response->assertStatus($status)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']])
            ->assertJsonPath('error.code', $code);

        $this->assertCount(1, $response->json(), 'Error body must contain only the "error" key.');
        $this->assertTrue(Str::isUlid($response->json('error.request_id')), 'request_id must be a ULID.');
        $this->assertIsObject($response->getData()->error->details, 'details must be a JSON object.');

        return $response;
    }
}
