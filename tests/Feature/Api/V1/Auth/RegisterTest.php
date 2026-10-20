<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API-AUTH-001 POST /auth/register.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'Lina',
            'email' => 'lina@example.test',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
            'terms_accepted' => true,
            'terms_version' => '2026-10-01',
            'device_name' => 'Pixel 9',
        ], $overrides);
    }

    public function test_registers_and_returns_user_and_token(): void
    {
        $this->freezeSecond();

        $response = $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertExactJsonStructure(['data' => [
                'user' => ['id', 'display_name', 'email', 'created_at'],
                'token' => ['access_token', 'token_type', 'expires_at'],
            ]])
            ->assertJsonPath('data.user.display_name', 'Lina')
            ->assertJsonPath('data.user.email', 'lina@example.test')
            ->assertJsonPath('data.user.created_at', now()->utc()->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.token.token_type', 'Bearer')
            ->assertJsonPath('data.token.expires_at', now()->addDays(60)->utc()->format('Y-m-d\TH:i:s\Z'));

        $this->assertTrue(Str::isUlid($response->json('data.user.id')));

        $user = User::sole();
        $this->assertTrue(Hash::check('correct-horse', $user->password));
        $this->assertSame('2026-10-01', $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame('Pixel 9', $user->tokens()->sole()->name);

        $this->withToken($response->json('data.token.access_token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_email_is_stored_trimmed_and_lowercased(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['email' => '  Lina@Example.TEST ']))
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'lina@example.test');
    }

    public function test_terms_version_defaults_to_current_configured_version(): void
    {
        config(['stashly.terms.version' => '2026-09-15']);

        $this->postJson('/api/v1/auth/register', $this->payload(['terms_version' => null]))->assertCreated();

        $this->assertSame('2026-09-15', User::sole()->terms_version);
    }

    public function test_device_name_is_optional(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['device_name' => null]))->assertCreated();

        $this->assertSame('unnamed device', User::sole()->tokens()->sole()->name);
    }

    public function test_duplicate_email_is_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'lina@example.test']);

        $response = $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'LINA@example.test']));

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['email']]]);
        $this->assertSame(1, User::count());
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_is_rejected(array $overrides, string $field): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload($overrides));

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => [$field]]]);
        $this->assertSame(0, User::count());
    }

    public static function invalidPayloads(): array
    {
        return [
            'terms not accepted' => [['terms_accepted' => false], 'terms_accepted'],
            'terms missing' => [['terms_accepted' => null], 'terms_accepted'],
            'terms as "yes" string' => [['terms_accepted' => 'yes'], 'terms_accepted'],
            'password mismatch' => [['password_confirmation' => 'something-else'], 'password'],
            'password 7 chars' => [['password' => str_repeat('a', 7), 'password_confirmation' => str_repeat('a', 7)], 'password'],
            'password 129 chars' => [['password' => str_repeat('a', 129), 'password_confirmation' => str_repeat('a', 129)], 'password'],
            'display name missing' => [['display_name' => null], 'display_name'],
            'display name blank' => [['display_name' => '   '], 'display_name'],
            'display name 101 chars' => [['display_name' => str_repeat('a', 101)], 'display_name'],
            'email malformed' => [['email' => 'not-an-email'], 'email'],
            'email missing' => [['email' => null], 'email'],
        ];
    }

    public function test_boundary_lengths_are_accepted(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'display_name' => str_repeat('a', 100),
            'password' => str_repeat('p', 128),
            'password_confirmation' => str_repeat('p', 128),
        ]))->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'short@example.test',
            'password' => str_repeat('p', 8),
            'password_confirmation' => str_repeat('p', 8),
        ]))->assertCreated();
    }

    public function test_internal_fields_cannot_be_mass_assigned(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'id' => '01JAAAAAAAAAAAAAAAAAAAAAAA',
            'email_verified_at' => '2020-01-01 00:00:00',
            'remember_token' => 'injected',
        ]))->assertCreated();

        $user = User::sole();
        $this->assertNotSame('01JAAAAAAAAAAAAAAAAAAAAAAA', $user->id);
        $this->assertNull($user->email_verified_at);
        $this->assertNotSame('injected', $user->remember_token);
    }

    public function test_response_never_contains_password_material(): void
    {
        $content = $this->postJson('/api/v1/auth/register', $this->payload())->getContent();

        $this->assertStringNotContainsString('correct-horse', $content);
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('remember_token', $content);
    }

    public function test_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/register', $this->payload(['email' => "u$i@example.test"]))->assertCreated();
        }

        $response = $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'u10@example.test']));
        $this->assertApiError($response, 429, 'RATE_LIMITED')->assertHeader('Retry-After');
    }
}
