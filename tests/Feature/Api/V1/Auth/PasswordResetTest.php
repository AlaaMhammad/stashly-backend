<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * API-AUTH-005 forgot-password, API-AUTH-009 verify-reset-code, API-AUTH-006 reset-password with a
 * 6-digit code (DEC-040), the reset e-mail, and the /reset-password web page. SEC-007, SEC-008.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'If an account exists for this email, a password reset code has been sent.';

    private function forgot(string $email = 'lina@example.test')
    {
        return $this->postJson('/api/v1/auth/forgot-password', ['email' => $email]);
    }

    private function verify(string $code, string $email = 'lina@example.test')
    {
        return $this->postJson('/api/v1/auth/verify-reset-code', ['email' => $email, 'code' => $code]);
    }

    private function reset(string $code, string $email = 'lina@example.test', string $password = 'brand-new-password')
    {
        return $this->postJson('/api/v1/auth/reset-password', [
            'email' => $email, 'code' => $code, 'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    private function wrongCode(string $real): string
    {
        return $real === '000000' ? '111111' : '000000';
    }

    // ------------------------------------------------------------------ forgot-password and the e-mail

    public function test_forgot_password_sends_a_six_digit_code_by_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'lina@example.test']);

        $this->forgot('Lina@Example.test')->assertOk()->assertExactJson(['data' => ['message' => self::MESSAGE]]);

        Notification::assertSentTo($user, PasswordResetCodeNotification::class, function ($notification) use ($user) {
            $this->assertNotInstanceOf(ShouldQueue::class, $notification, 'No queue worker runs on shared hosting.');
            $this->assertMatchesRegularExpression('/^\d{6}$/', $notification->code);

            $mail = $notification->toMail($user);
            $this->assertSame(['emails.auth.password-reset-otp', 'emails.auth.password-reset-otp-text'], $mail->view);
            $this->assertSame($notification->code, $mail->viewData['code']);
            $this->assertSame(15, $mail->viewData['minutes']);
            $this->assertStringContainsString($notification->code, $mail->subject);

            return true;
        });
    }

    public function test_the_email_is_delivered_without_a_queue_worker_and_uses_the_otp_template(): void
    {
        User::factory()->create(['email' => 'lina@example.test', 'display_name' => 'Lina <b>Example</b>']);

        $this->forgot()->assertOk();

        Queue::assertNothingPushed();
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages[0]->getOriginalMessage();
        $this->assertSame('lina@example.test', $mail->getTo()[0]->getAddress());

        $code = $this->codeFromMail($mail->getTextBody());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $html = $mail->getHtmlBody();

        // The code in its own box, with the specified typography.
        $this->assertStringContainsString('font-size: 34px; letter-spacing: 8px; font-weight: 800; color: #4f46e5; font-family: monospace; background: #f1f5f9;', $html);
        $this->assertMatchesRegularExpression('#<span class="otp" style="[^"]*">'.$code.'</span>#', $html);
        // Branding, expiry and the security alert.
        $this->assertStringContainsString('#4f46e5', $html);
        $this->assertStringContainsString('<table role="presentation"', $html);
        $this->assertStringContainsString('expires in <strong style="color: #0f172a;">15 minutes</strong>', $html);
        $this->assertStringContainsString("If you didn't request this, ignore this email.", $html);
        $this->assertStringContainsString('#fef2f2', $html);
        $this->assertStringContainsString('#b91c1c', $html);
        $this->assertStringContainsString(config('app.name'), $html);
        // The display name is escaped, and there is no link to click.
        $this->assertStringNotContainsString('<b>Example</b>', $html);
        $this->assertStringContainsString('Lina &lt;b&gt;Example&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('http', $mail->getTextBody());
        // Plain-text alternative.
        $this->assertStringContainsString("Your password reset code is: $code", $mail->getTextBody());
        $this->assertStringContainsString('15 minutes', $mail->getTextBody());
        $this->assertStringContainsString("If you didn't request this, ignore this email.", $mail->getTextBody());
        $this->assertSame("$code is your ".config('app.name').' password reset code', $mail->getSubject());
    }

    private function codeFromMail(string $text): string
    {
        preg_match('/reset code is: (\d{6})/', $text, $m);

        return $m[1] ?? '';
    }

    public function test_the_code_is_stored_only_as_a_keyed_hash_that_expires_in_15_minutes(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        $row = DB::table('password_reset_codes')->where('email', 'lina@example.test')->first();

        $this->assertNotSame($code, $row->code_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->code_hash);
        $this->assertSame(hash_hmac('sha256', "lina@example.test|$code", config('app.key')), $row->code_hash);
        $this->assertNotSame(hash('sha256', $code), $row->code_hash, 'Not a bare hash of a one-in-a-million value.');
        $this->assertSame(0, (int) $row->attempts);
        $this->assertSame(now()->addMinutes(15)->format('Y-m-d H:i:s'), substr((string) $row->expires_at, 0, 19));
    }

    public function test_codes_are_always_six_digits_including_leading_zeros(): void
    {
        $user = User::factory()->create();
        $seen = [];

        for ($i = 0; $i < 300; $i++) {
            $code = $this->resetCodeFor($user);
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
            $seen[$code[0]] = true;
        }

        $this->assertGreaterThan(5, count($seen), 'The first digit varies (including 0).');
    }

    public function test_forgot_password_answers_identically_for_unknown_email(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'lina@example.test']);

        $known = $this->forgot();
        $unknown = $this->forgot('nobody@example.test');

        $unknown->assertOk();
        $this->assertSame($known->getContent(), $unknown->getContent());
        Notification::assertSentTimes(PasswordResetCodeNotification::class, 1);
        $this->assertSame(1, DB::table('password_reset_codes')->count());
    }

    public function test_a_second_request_within_the_cooldown_sends_nothing_new_and_looks_the_same(): void
    {
        User::factory()->create(['email' => 'lina@example.test']);

        $first = $this->forgot();
        $code = $this->codeFromMail(app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage()->getTextBody());
        $second = $this->forgot();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->verify($code)->assertOk();
    }

    public function test_a_new_request_after_the_cooldown_replaces_the_old_code(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $old = $this->resetCodeFor($user);

        $this->travel(61)->seconds();
        $this->forgot()->assertOk();

        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame(1, DB::table('password_reset_codes')->count());
        $new = $this->codeFromMail(app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage()->getTextBody());
        $this->assertNotSame('', $new);
        if ($new !== $old) {
            $this->assertApiError($this->verify($old), 422, 'TOKEN_INVALID_OR_EXPIRED');
        }
        $this->verify($new)->assertOk();
    }

    public function test_forgot_password_validates_email_format(): void
    {
        $this->assertApiError($this->forgot('not-an-email'), 422, 'VALIDATION_FAILED');
    }

    public function test_requests_are_limited_per_email_and_per_ip(): void
    {
        User::factory()->create(['email' => 'lina@example.test']);

        for ($i = 0; $i < 3; $i++) {
            $this->forgot()->assertOk();
        }
        $this->assertApiError($this->forgot(), 429, 'RATE_LIMITED')->assertHeader('Retry-After');

        // One client cannot mail many different addresses either.
        for ($i = 0; $i < 7; $i++) {
            $this->forgot("user$i@example.test")->assertOk();
        }
        $this->assertApiError($this->forgot('another@example.test'), 429, 'RATE_LIMITED');
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/v1/auth/forgot-password', ['email' => 'another@example.test'])->assertOk();
    }

    public function test_a_mail_transport_failure_is_logged_safely_and_the_api_answers_as_usual(): void
    {
        Mail::extend('failing', fn () => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Failed to authenticate on SMTP server with username "noreply@yourdomain.com" using the mechanisms LOGIN.');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'failing']]);
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = [$e->level, $e->message, $e->context];
        });

        $known = $this->forgot();
        $unknown = $this->forgot('nobody@example.test');

        $known->assertOk();
        $this->assertSame($unknown->getContent(), $known->getContent(), 'A failing SMTP server must not reveal that the account exists.');

        $errors = array_values(array_filter($logged, fn ($l) => $l[0] === 'error'));
        $this->assertCount(1, $errors);
        $this->assertSame('Password reset email could not be sent.', $errors[0][1]);
        $this->assertSame($user->id, $errors[0][2]['user_id']);
        $this->assertSame(TransportException::class, $errors[0][2]['exception']);
        $text = json_encode($errors);
        foreach (['lina@example.test', 'noreply@yourdomain.com', 'code_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertStringContainsString('[email]', $text);

        // The unusable code is gone, so the user can ask again at once.
        $this->assertSame(0, DB::table('password_reset_codes')->count());
    }

    public function test_the_smtp_timeout_is_bounded(): void
    {
        $this->assertSame(10, config('mail.mailers.smtp.timeout'));
    }

    // ------------------------------------------------------------------ verify-reset-code

    public function test_verify_accepts_the_current_code_without_using_it_up(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        $this->verify($code)->assertOk()->assertExactJson(['data' => ['valid' => true]]);
        $this->verify($code)->assertOk();
        $this->reset($code)->assertOk();
    }

    public function test_verify_rejects_a_wrong_code_and_an_unknown_email_with_the_same_error(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        $wrong = $this->assertApiError($this->verify($this->wrongCode($code)), 422, 'TOKEN_INVALID_OR_EXPIRED');
        $unknown = $this->assertApiError($this->verify($code, 'nobody@example.test'), 422, 'TOKEN_INVALID_OR_EXPIRED');

        $this->assertSame($wrong->json('error.message'), $unknown->json('error.message'));
    }

    public function test_five_wrong_guesses_invalidate_the_code_even_for_the_right_answer(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);
        $wrong = $this->wrongCode($code);

        for ($i = 0; $i < 4; $i++) {
            $this->assertApiError($this->verify($wrong), 422, 'TOKEN_INVALID_OR_EXPIRED');
        }
        $this->assertSame(4, (int) DB::table('password_reset_codes')->value('attempts'));

        $this->assertApiError($this->verify($wrong), 422, 'TOKEN_INVALID_OR_EXPIRED');
        $this->assertSame(0, DB::table('password_reset_codes')->count(), 'The code is deleted after the fifth wrong guess.');
        $this->assertApiError($this->verify($code), 422, 'TOKEN_INVALID_OR_EXPIRED');
        $this->assertApiError($this->reset($code), 422, 'TOKEN_INVALID_OR_EXPIRED');
    }

    public function test_a_right_guess_before_the_limit_still_works(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        for ($i = 0; $i < 4; $i++) {
            $this->verify($this->wrongCode($code))->assertStatus(422);
        }

        $this->verify($code)->assertOk();
    }

    public function test_the_code_expires_after_15_minutes(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        $this->travel(14)->minutes();
        $this->verify($code)->assertOk();

        $this->travel(2)->minutes();
        $this->assertApiError($this->verify($code), 422, 'TOKEN_INVALID_OR_EXPIRED');
        $this->assertApiError($this->reset($code), 422, 'TOKEN_INVALID_OR_EXPIRED');
        $this->assertSame(0, DB::table('password_reset_codes')->count(), 'An expired code is deleted.');
    }

    public function test_the_code_format_is_validated(): void
    {
        config(['stashly.rate_limits.reset_code_per_email_per_hour' => 1000, 'stashly.rate_limits.reset_code_per_ip_per_hour' => 1000]);
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $this->resetCodeFor($user);

        foreach ([null, '', '12345', '1234567', 'abcdef', '12a456', '12.456', ['123456'], true] as $bad) {
            $this->assertApiError($this->postJson('/api/v1/auth/verify-reset-code', ['email' => 'lina@example.test', 'code' => $bad]), 422, 'VALIDATION_FAILED');
        }
        $this->assertApiError($this->postJson('/api/v1/auth/verify-reset-code', ['code' => '123456']), 422, 'VALIDATION_FAILED');
        $this->assertSame(0, (int) DB::table('password_reset_codes')->value('attempts'), 'Malformed codes are not counted as guesses.');
    }

    public function test_spaces_in_the_code_are_ignored_and_the_email_is_case_insensitive(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        $this->verify(substr($code, 0, 3).' '.substr($code, 3), ' Lina@Example.test ')->assertOk();
    }

    // ------------------------------------------------------------------ reset-password

    public function test_reset_sets_the_new_password_and_revokes_every_token(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $session = $this->tokenFor($user);
        $code = $this->resetCodeFor($user);

        $this->reset($code)->assertOk()->assertExactJson(['data' => ['message' => 'Your password has been reset.']]);

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('password_reset_codes')->count());
        $this->resetAuth();
        $this->assertApiError($this->withToken($session)->getJson('/api/v1/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_the_code_is_single_use(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        $this->reset($code)->assertOk();
        $this->assertApiError($this->reset($code, password: 'another-password-1'), 422, 'TOKEN_INVALID_OR_EXPIRED');

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_a_wrong_code_and_an_unknown_email_get_the_same_error_and_change_nothing(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        $wrong = $this->assertApiError($this->reset($this->wrongCode($code)), 422, 'TOKEN_INVALID_OR_EXPIRED');
        $unknown = $this->assertApiError($this->reset($code, 'nobody@example.test'), 422, 'TOKEN_INVALID_OR_EXPIRED');

        $this->assertSame($wrong->json('error.message'), $unknown->json('error.message'));
        $this->assertFalse(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_a_code_for_one_email_does_not_work_for_another_account(): void
    {
        $lina = User::factory()->create(['email' => 'lina@example.test']);
        $other = User::factory()->create(['email' => 'other@example.test']);
        $code = $this->resetCodeFor($lina);

        $this->assertApiError($this->reset($code, 'other@example.test'), 422, 'TOKEN_INVALID_OR_EXPIRED');
        $this->assertFalse(Hash::check('brand-new-password', $other->fresh()->password));
    }

    public function test_reset_guesses_count_towards_the_same_five_attempts(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        for ($i = 0; $i < 5; $i++) {
            $this->reset($this->wrongCode($code))->assertStatus(422);
        }

        $this->assertApiError($this->reset($code), 422, 'TOKEN_INVALID_OR_EXPIRED');
        $this->assertFalse(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_a_wrong_new_password_does_not_use_up_the_code(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        $this->assertApiError($this->reset($code, password: 'short'), 422, 'VALIDATION_FAILED');
        $this->reset($code)->assertOk();
    }

    public function test_reset_validates_the_new_password_and_the_code(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        $this->assertApiError($this->postJson('/api/v1/auth/reset-password', [
            'email' => 'lina@example.test', 'code' => $code, 'password' => 'brand-new-password', 'password_confirmation' => 'different',
        ]), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->reset('12345'), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->reset($code, password: str_repeat('x', 129)), 422, 'VALIDATION_FAILED');
    }

    public function test_the_old_token_field_is_no_longer_accepted(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'lina@example.test', 'token' => $code, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ]);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED');
        $this->assertArrayHasKey('code', $response->json('error.details'));
    }

    public function test_a_google_only_account_can_set_its_first_password_with_a_code(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test', 'password' => null, 'google_id' => 'sub-1']);

        $this->forgot()->assertOk();
        $code = $this->codeFromMail(app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage()->getTextBody());
        $this->reset($code)->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertSame('sub-1', $user->fresh()->google_id);
    }

    public function test_the_reset_endpoints_are_limited_per_email_and_ip(): void
    {
        config(['stashly.rate_limits.reset_code_per_email_per_hour' => 3]);
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $code = $this->resetCodeFor($user);

        for ($i = 0; $i < 3; $i++) {
            $this->verify($this->wrongCode($code))->assertStatus(422);
            if ($i === 1) {
                $code = $this->resetCodeFor($user);
            }
        }

        $this->assertApiError($this->verify($code), 429, 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->assertApiError($this->reset($code), 429, 'RATE_LIMITED');
        $this->verify($code, 'nobody@example.test')->assertStatus(422);
    }

    // ------------------------------------------------------------------ web page

    public function test_reset_page_renders_the_code_form_with_safe_headers(): void
    {
        $response = $this->get('/reset-password?email=lina%40example.test')->assertOk();

        $response->assertSee('name="code"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('value="lina@example.test"', false);
        $response->assertSee('data-endpoint="'.url('/api/v1/auth/reset-password').'"', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_reset_page_escapes_query_values(): void
    {
        $response = $this->get('/reset-password?email='.urlencode('"><script>alert(1)</script>'))->assertOk();

        $this->assertStringNotContainsString('<script>alert(1)', $response->getContent());
    }

    public function test_reset_page_works_without_query_values(): void
    {
        $this->get('/reset-password')->assertOk()->assertSee('name="email"', false);
    }
}
