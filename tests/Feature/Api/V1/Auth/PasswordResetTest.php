<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * API-AUTH-005 forgot-password, API-AUTH-006 reset-password, the reset email,
 * and the /reset-password web page. SEC-007, SEC-008.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_queues_a_reset_email_with_the_web_page_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'lina@example.test']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'Lina@Example.test'])
            ->assertOk()
            ->assertExactJson(['data' => [
                'message' => 'If an account exists for this email, a password reset link has been sent.',
            ]]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
            $this->assertInstanceOf(ShouldQueue::class, $notification);

            $mail = $notification->toMail($user);
            $this->assertSame(
                url('/reset-password').'?'.http_build_query(['token' => $notification->token, 'email' => 'lina@example.test']),
                $mail->actionUrl,
            );
            $this->assertStringContainsString('60 minutes', implode(' ', $mail->outroLines));

            return true;
        });
    }

    public function test_forgot_password_answers_identically_for_unknown_email(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'lina@example.test']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'lina@example.test']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test']);

        $unknown->assertOk();
        $this->assertSame($known->getContent(), $unknown->getContent());
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_forgot_password_answers_identically_when_broker_throttles(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'lina@example.test']);

        $first = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'lina@example.test']);
        $second = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'lina@example.test']);

        $this->assertSame($first->getContent(), $second->getContent());
    }

    public function test_forgot_password_validates_email_format(): void
    {
        $this->assertApiError(
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email']),
            422,
            'VALIDATION_FAILED',
        );
    }

    public function test_reset_sets_the_new_password_and_revokes_every_token(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $session = $this->tokenFor($user);
        $resetToken = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'lina@example.test',
            'token' => $resetToken,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk()->assertExactJson(['data' => ['message' => 'Your password has been reset.']]);

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertApiError($this->withToken($session)->getJson('/api/v1/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_reset_token_is_single_use(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $resetToken = Password::broker()->createToken($user);
        $payload = [
            'email' => 'lina@example.test',
            'token' => $resetToken,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();

        $reuse = $this->postJson('/api/v1/auth/reset-password', array_merge($payload, [
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ]));
        $this->assertApiError($reuse, 422, 'TOKEN_INVALID_OR_EXPIRED');
        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_reset_token_expires_after_60_minutes(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $resetToken = Password::broker()->createToken($user);

        $this->travel(61)->minutes();

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'lina@example.test',
            'token' => $resetToken,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $this->assertApiError($response, 422, 'TOKEN_INVALID_OR_EXPIRED');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_wrong_token_and_unknown_email_get_the_same_error(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        Password::broker()->createToken($user);
        $attempt = fn (string $email) => $this->postJson('/api/v1/auth/reset-password', [
            'email' => $email,
            'token' => 'not-the-token',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $wrongToken = $attempt('lina@example.test');
        $unknownEmail = $attempt('nobody@example.test');

        $this->assertApiError($wrongToken, 422, 'TOKEN_INVALID_OR_EXPIRED');
        $this->assertApiError($unknownEmail, 422, 'TOKEN_INVALID_OR_EXPIRED');
        $this->assertSame($wrongToken->json('error.message'), $unknownEmail->json('error.message'));
    }

    public function test_reset_validates_the_new_password(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $resetToken = Password::broker()->createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'lina@example.test',
            'token' => $resetToken,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['password']]]);
    }

    public function test_reset_page_renders_the_form_with_safe_headers(): void
    {
        $response = $this->get('/reset-password?token=abc123&email=lina%40example.test');

        $response->assertOk()
            ->assertSee('Reset your password')
            ->assertSee('data-token="abc123"', false)
            ->assertSee('data-email="lina@example.test"', false)
            ->assertSee(url('/api/v1/auth/reset-password'), false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_reset_page_escapes_query_values(): void
    {
        $response = $this->get('/reset-password?token=t&email='.urlencode('"><script>alert(1)</script>'));

        $response->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_reset_page_without_token_shows_an_error_instead_of_the_form(): void
    {
        $this->get('/reset-password?email=lina%40example.test')
            ->assertOk()
            ->assertSee('This reset link is incomplete')
            ->assertDontSee('id="reset-form"', false);
    }
}
