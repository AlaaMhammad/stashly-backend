<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-AUTH-002 POST /auth/login.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_return_user_and_token(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'lina@example.test',
            'password' => 'password',
            'device_name' => 'Pixel 9',
        ]);

        $response->assertOk()
            ->assertExactJsonStructure(['data' => [
                'user' => ['id', 'display_name', 'email', 'created_at'],
                'token' => ['access_token', 'token_type', 'expires_at'],
            ]])
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token.token_type', 'Bearer');

        $this->assertSame('Pixel 9', $user->tokens()->sole()->name);
        $this->withToken($response->json('data.token.access_token'))->getJson('/api/v1/me')->assertOk();
    }

    public function test_email_lookup_ignores_case_and_surrounding_spaces(): void
    {
        User::factory()->create(['email' => 'lina@example.test']);

        $this->postJson('/api/v1/auth/login', ['email' => ' LINA@Example.test ', 'password' => 'password'])
            ->assertOk();
    }

    public function test_each_login_issues_a_separate_token(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);

        $first = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'password']);
        $second = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'password']);

        $this->assertNotSame($first->json('data.token.access_token'), $second->json('data.token.access_token'));
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_wrong_password_and_unknown_email_are_indistinguishable(): void
    {
        User::factory()->create(['email' => 'lina@example.test']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'wrong-password']);
        $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password']);
        $malformedEmail = $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email', 'password' => 'wrong-password']);

        foreach ([$wrongPassword, $unknownEmail, $malformedEmail] as $response) {
            $this->assertApiError($response, 401, 'INVALID_CREDENTIALS');
        }

        $strip = fn ($response) => array_diff_key($response->json('error'), ['request_id' => true]);
        $this->assertSame($strip($wrongPassword), $strip($unknownEmail));
        $this->assertSame($strip($wrongPassword), $strip($malformedEmail));
    }

    public function test_failed_login_issues_no_token(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'wrong-password']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_missing_fields_fail_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['email', 'password']]]);
    }

    public function test_is_rate_limited_by_email_and_ip(): void
    {
        User::factory()->create(['email' => 'lina@example.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'wrong-password']);
        }

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'password']);
        $this->assertApiError($response, 429, 'RATE_LIMITED')->assertHeader('Retry-After');
    }
}
