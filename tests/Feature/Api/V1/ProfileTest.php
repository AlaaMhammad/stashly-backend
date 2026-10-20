<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * API-PROF-001 GET /me, API-PROF-002 PATCH /me, API-AUTH-007 PUT /me/password,
 * API-PROF-005 DELETE /me. SEC-018.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_only_the_documented_user_fields(): void
    {
        $this->travelTo('2026-10-16 09:30:12');
        $user = User::factory()->create(['display_name' => 'Lina', 'email' => 'lina@example.test']);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJson(['data' => ['user' => [
                'id' => $user->id,
                'display_name' => 'Lina',
                'email' => 'lina@example.test',
                'created_at' => '2026-10-16T09:30:12Z',
            ]]]);
    }

    public function test_display_name_can_be_changed_and_is_trimmed(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->patchJson('/api/v1/me', ['display_name' => '  New Name  '])
            ->assertOk()
            ->assertJsonPath('data.user.display_name', 'New Name');

        $this->assertSame('New Name', $user->fresh()->display_name);
    }

    public function test_display_name_bounds(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withToken($token)->patchJson('/api/v1/me', ['display_name' => str_repeat('a', 100)])->assertOk();
        $this->withToken($token)->patchJson('/api/v1/me', ['display_name' => 'a'])->assertOk();

        foreach ([str_repeat('a', 101), '   ', null] as $invalid) {
            $this->assertApiError(
                $this->withToken($token)->patchJson('/api/v1/me', ['display_name' => $invalid]),
                422,
                'VALIDATION_FAILED',
            );
        }
    }

    public function test_email_cannot_be_changed(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);

        $response = $this->withToken($this->tokenFor($user))
            ->patchJson('/api/v1/me', ['display_name' => 'Lina', 'email' => 'new@example.test']);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['email']]]);
        $this->assertSame('lina@example.test', $user->fresh()->email);
    }

    public function test_change_password_revokes_other_tokens_but_keeps_the_current_one(): void
    {
        $user = User::factory()->create();
        $current = $this->tokenFor($user, 'phone');
        $other = $this->tokenFor($user, 'tablet');

        $this->withToken($current)->putJson('/api/v1/me/password', [
            'current_password' => 'password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk()->assertExactJson(['data' => ['message' => 'Your password has been changed.']]);

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertSame(['phone'], $user->tokens()->pluck('name')->all());

        $this->resetAuth();
        $this->withToken($current)->getJson('/api/v1/me')->assertOk();
        $this->resetAuth();
        $this->assertApiError($this->withToken($other)->getJson('/api/v1/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_change_password_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this->withToken($this->tokenFor($user))->putJson('/api/v1/me/password', [
            'current_password' => 'wrong-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['current_password']]]);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_new_password_must_differ_from_the_current_one(): void
    {
        $user = User::factory()->create();

        $response = $this->withToken($this->tokenFor($user))->putJson('/api/v1/me/password', [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['password']]]);
    }

    public function test_delete_account_requires_the_password(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->assertApiError($this->withToken($token)->deleteJson('/api/v1/me', ['password' => 'wrong-password']), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->withToken($token)->deleteJson('/api/v1/me'), 422, 'VALIDATION_FAILED');

        $this->assertModelExists($user);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_delete_account_removes_the_user_tokens_and_reset_tokens_only_for_that_user(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $token = $this->tokenFor($user, 'phone');
        $this->tokenFor($user, 'tablet');
        Password::broker()->createToken($user);

        $bystander = User::factory()->create(['email' => 'other@example.test']);
        $bystanderToken = $this->tokenFor($bystander);
        Password::broker()->createToken($bystander);

        $this->withToken($token)->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();

        $this->assertModelMissing($user);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'lina@example.test']);

        $this->assertModelExists($bystander);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'other@example.test']);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $this->resetAuth();
        $this->assertApiError($this->withToken($token)->getJson('/api/v1/me'), 401, 'UNAUTHENTICATED');
        $this->resetAuth();
        $this->withToken($bystanderToken)->getJson('/api/v1/me')->assertOk();
    }
}
