<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * SEC-017: no passwords, access tokens or reset tokens in logs.
 */
class SensitiveDataLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_flows_do_not_log_secrets(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $register = $this->postJson('/api/v1/auth/register', [
            'display_name' => 'Lina',
            'email' => 'lina@example.test',
            'password' => 'register-secret-1',
            'password_confirmation' => 'register-secret-1',
            'terms_accepted' => true,
        ])->assertCreated();
        $registerToken = $register->json('data.token.access_token');

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'register-secret-1'])->assertOk();
        $loginToken = $login->json('data.token.access_token');
        $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'wrong-secret-2']);

        $this->withToken($loginToken)->putJson('/api/v1/me/password', [
            'current_password' => 'register-secret-1',
            'password' => 'changed-secret-3',
            'password_confirmation' => 'changed-secret-3',
        ])->assertOk();

        $resetToken = $this->resetCodeFor(User::sole());
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'lina@example.test',
            'code' => $resetToken,
            'password' => 'reset-secret-4',
            'password_confirmation' => 'reset-secret-4',
        ])->assertOk();

        $this->resetAuth();
        $final = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'reset-secret-4'])
            ->json('data.token.access_token');
        $this->resetAuth();
        $this->withToken($final)->deleteJson('/api/v1/me', ['password' => 'reset-secret-4'])->assertNoContent();

        $this->assertNotEmpty($logged, 'Expected at least the account-deletion audit entry.');
        $all = implode("\n", $logged);
        foreach (['register-secret-1', 'wrong-secret-2', 'changed-secret-3', 'reset-secret-4', $resetToken, $registerToken, $loginToken, $final] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertStringNotContainsString('lina@example.test', $all, 'Audit entries must not contain the email.');
    }
}
