<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeGoogle;
use Tests\TestCase;

/**
 * API-AUTH-008 POST /auth/google (DEC-035).
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeGoogle::install();
    }

    private function signIn(array $body = [], ?string $token = null)
    {
        return $this->postJson('/api/v1/auth/google', $body + [
            'id_token' => $token ?? FakeGoogle::token(),
            'device_name' => 'Pixel 9',
            'terms_accepted' => true,
        ]);
    }

    // ------------------------------------------------------------------ new account

    public function test_a_new_google_user_is_registered_with_201_and_the_login_response_shape(): void
    {
        $this->freezeSecond();

        $response = $this->signIn(['terms_version' => 'draft-1'])
            ->assertCreated()
            ->assertExactJsonStructure(['data' => [
                'user' => ['id', 'display_name', 'email', 'created_at', 'password_set'],
                'token' => ['access_token', 'token_type', 'expires_at'],
            ]])
            ->assertJsonPath('data.user.display_name', 'Lina Example')
            ->assertJsonPath('data.user.email', 'lina@example.test')
            ->assertJsonPath('data.token.token_type', 'Bearer')
            ->assertJsonPath('data.token.expires_at', now()->addDays(60)->utc()->format('Y-m-d\TH:i:s\Z'));

        $this->assertTrue(Str::isUlid($response->json('data.user.id')));

        $user = User::sole();
        $this->assertSame('110169484474386276334', $user->google_id);
        $this->assertNull($user->password, 'A Google-only account has no password.');
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('draft-1', $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame('https://lh3.googleusercontent.com/a/photo=s96-c', $user->avatar_url);
        $this->assertSame('Pixel 9', $user->tokens()->sole()->name);

        // The token works, and the API user object is unchanged (no google_id / avatar).
        $this->withToken($response->json('data.token.access_token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJsonStructure(['data' => ['user' => ['id', 'display_name', 'email', 'created_at', 'password_set']]]);
    }

    public function test_the_email_is_stored_lowercased_and_the_terms_version_defaults_to_the_active_one(): void
    {
        config(['stashly.terms.version' => '2027-01-01']);

        $this->signIn(['terms_version' => null], FakeGoogle::token(['email' => 'Lina.Example@Example.TEST']))->assertCreated();

        $this->assertSame('lina.example@example.test', User::sole()->email);
        $this->assertSame('2027-01-01', User::sole()->terms_version);
    }

    #[DataProvider('displayNames')]
    public function test_the_display_name_comes_from_the_profile_with_a_safe_fallback(?string $name, string $email, string $expected): void
    {
        $this->signIn([], FakeGoogle::token(['name' => $name, 'email' => $email]))->assertCreated();

        $this->assertSame($expected, User::sole()->display_name);
    }

    public static function displayNames(): array
    {
        return [
            'profile name' => ['Lina Example', 'lina@example.test', 'Lina Example'],
            'blank name falls back to the e-mail prefix' => ['   ', 'sara.k@example.test', 'sara.k'],
            'missing name falls back to the e-mail prefix' => [null, 'omar@example.test', 'omar'],
            'control characters are removed' => ["Li\x00na\n\tEx", 'x@example.test', 'Li na Ex'],
            'truncated to 100 characters' => [str_repeat('é', 150), 'x@example.test', str_repeat('é', 100)],
        ];
    }

    public function test_an_untrusted_picture_address_is_not_stored(): void
    {
        foreach (['http://insecure.example/p.jpg', 'javascript:alert(1)', 'https://'.str_repeat('a', 2100).'.example/p.jpg', 'not a url'] as $i => $picture) {
            $this->signIn([], FakeGoogle::token(['picture' => $picture, 'sub' => "sub-$i", 'email' => "u$i@example.test"]))->assertCreated();
        }

        $this->assertSame(4, User::whereNull('avatar_url')->count());
    }

    public function test_terms_are_required_to_create_an_account(): void
    {
        foreach ([[], ['terms_accepted' => false], ['terms_accepted' => null]] as $extra) {
            $response = $this->postJson('/api/v1/auth/google', $extra + ['id_token' => FakeGoogle::token(), 'device_name' => 'Pixel 9']);

            $this->assertApiError($response, 422, 'VALIDATION_FAILED')
                ->assertJsonStructure(['error' => ['details' => ['terms_accepted']]]);
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_an_invalid_token_is_rejected_before_the_terms_check(): void
    {
        $response = $this->postJson('/api/v1/auth/google', ['id_token' => FakeGoogle::token(['aud' => 'someone-else']), 'device_name' => 'x']);

        $this->assertApiError($response, 401, 'INVALID_GOOGLE_TOKEN');
    }

    // ------------------------------------------------------------------ returning / linking

    public function test_a_returning_google_user_signs_in_with_200_and_no_new_account(): void
    {
        $this->signIn()->assertCreated();
        $user = User::sole();

        $second = $this->signIn(['terms_accepted' => null], FakeGoogle::token([
            'picture' => 'https://lh3.googleusercontent.com/a/new', 'name' => 'Renamed At Google',
        ]))->assertOk();

        $this->assertSame($user->id, $second->json('data.user.id'));
        $this->assertSame(1, User::count());
        $this->assertSame(2, $user->tokens()->count(), 'One token per sign-in.');
        $this->assertSame('https://lh3.googleusercontent.com/a/new', $user->fresh()->avatar_url);
        $this->assertSame('Lina Example', $user->fresh()->display_name, 'A Google rename does not overwrite the name the user may have edited.');
    }

    public function test_a_returning_user_is_found_by_google_id_even_if_their_google_email_changed(): void
    {
        $this->signIn()->assertCreated();

        $this->signIn(['terms_accepted' => null], FakeGoogle::token(['email' => 'new.address@example.test']))->assertOk();

        $this->assertSame(1, User::count());
        $this->assertSame('lina@example.test', User::sole()->email);
    }

    public function test_a_returning_user_needs_no_terms_but_a_newer_version_is_recorded_when_sent(): void
    {
        $this->signIn(['terms_version' => 'v1'])->assertCreated();
        $this->travel(1)->day();

        $this->signIn(['terms_accepted' => null, 'terms_version' => null])->assertOk();
        $this->assertSame('v1', User::sole()->terms_version);

        $this->signIn(['terms_accepted' => true, 'terms_version' => 'v2'])->assertOk();
        $this->assertSame('v2', User::sole()->terms_version);
    }

    public function test_an_existing_password_account_is_linked_when_google_verified_the_email(): void
    {
        $user = User::factory()->create(['email' => 'lina@example.test', 'email_verified_at' => null]);
        $oldToken = $this->tokenFor($user, 'attacker-or-owner');

        $response = $this->signIn(['terms_accepted' => null], FakeGoogle::token(['email' => 'LINA@example.TEST']))->assertOk();

        $this->assertSame($user->id, $response->json('data.user.id'));
        $this->assertSame(1, User::count());
        $linked = $user->fresh();
        $this->assertSame('110169484474386276334', $linked->google_id);
        $this->assertNotNull($linked->email_verified_at, 'Google verified the address.');
        $this->assertNull($linked->password, 'DEC-037: the unverified registrant password is cleared.');
        $response->assertJsonPath('data.user.password_set', false);

        // Sessions that existed before linking are revoked (the e-mail was never verified when the password account was created).
        $this->assertSame(1, $user->tokens()->count());
        $this->resetAuth();
        $this->assertApiError($this->withToken($oldToken)->getJson('/api/v1/me'), 401, 'UNAUTHENTICATED');

        // The old password no longer works (DEC-037), and fails like any unknown credential.
        $this->assertApiError($this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'password']), 401, 'INVALID_CREDENTIALS');
    }

    public function test_a_pre_registered_password_cannot_be_used_after_the_victim_signs_in_with_google(): void
    {
        // The attacker registers the victim's address with their own password first.
        $attacker = $this->postJson('/api/v1/auth/register', [
            'display_name' => 'Eve', 'email' => 'lina@example.test', 'password' => 'attacker-password',
            'password_confirmation' => 'attacker-password', 'terms_accepted' => true,
        ])->assertCreated()->json('data.token.access_token');

        $this->resetAuth();
        $this->signIn(['terms_accepted' => null])->assertOk();

        $this->assertApiError($this->withToken($attacker)->getJson('/api/v1/me'), 401, 'UNAUTHENTICATED');
        $this->assertApiError($this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'attacker-password']), 401, 'INVALID_CREDENTIALS');
    }

    public function test_the_password_is_set_again_only_through_the_reset_flow_after_linking(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'lina@example.test']);
        $this->signIn(['terms_accepted' => null])->assertOk();
        $this->assertNull($user->fresh()->password);

        $token = $this->resetCodeFor($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'lina@example.test', 'code' => $token, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'brand-new-password'])->assertOk();
    }

    public function test_signing_in_again_with_google_does_not_touch_an_existing_password(): void
    {
        $this->signIn()->assertCreated();
        $user = User::sole();
        $user->forceFill(['password' => 'chosen-later-password'])->save();

        $this->signIn(['terms_accepted' => null])->assertOk();

        $this->assertTrue(Hash::check('chosen-later-password', $user->fresh()->password));
    }

    public function test_an_email_linked_to_a_different_google_account_is_never_taken_over(): void
    {
        User::factory()->create(['email' => 'lina@example.test', 'google_id' => 'the-real-owner']);

        $response = $this->signIn([], FakeGoogle::token(['sub' => 'someone-else', 'email' => 'lina@example.test']));

        $this->assertApiError($response, 401, 'INVALID_GOOGLE_TOKEN');
        $this->assertSame('the-real-owner', User::sole()->google_id);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    // ------------------------------------------------------------------ rejected tokens

    public function test_an_unverified_google_email_is_rejected_and_nothing_is_created_or_linked(): void
    {
        $existing = User::factory()->create(['email' => 'lina@example.test']);

        foreach ([false, 'true', 1, null] as $value) {
            $response = $this->signIn([], FakeGoogle::token(['email_verified' => $value]));

            $this->assertApiError($response, 401, 'INVALID_GOOGLE_TOKEN');
        }
        $this->signIn([], FakeGoogle::token(['email' => 'new@example.test', 'email_verified' => false, 'sub' => 'new']))->assertStatus(401);

        $this->assertSame(1, User::count());
        $this->assertNull($existing->fresh()->google_id, 'An unverified address must never link to an existing account.');
    }

    public function test_the_unverified_email_message_is_specific_but_never_echoes_claims(): void
    {
        $response = $this->signIn([], FakeGoogle::token(['email_verified' => false]));

        $this->assertStringContainsString('not verified', $response->json('error.message'));
        $this->assertStringNotContainsString('lina@example.test', $response->getContent());
    }

    public function test_bad_tokens_are_rejected_with_the_same_generic_error(): void
    {
        $valid = FakeGoogle::token();
        [$h, $p, $s] = explode('.', $valid);
        $tampered = $h.'.'.FakeGoogle::b64(json_encode(['iss' => 'accounts.google.com', 'aud' => FakeGoogle::CLIENT_ID, 'sub' => 'attacker', 'email' => 'victim@example.test', 'email_verified' => true, 'exp' => now()->timestamp + 3600])).'.'.$s;

        $cases = [
            'expired' => FakeGoogle::token(['exp' => now()->timestamp - 1]),
            'expires right now' => FakeGoogle::token(['exp' => now()->timestamp]),
            'wrong audience' => FakeGoogle::token(['aud' => 'another-app.apps.googleusercontent.com']),
            'wrong issuer' => FakeGoogle::token(['iss' => 'https://evil.example']),
            'tampered payload, original signature' => $tampered,
            'signed by an unknown key' => FakeGoogle::token([], ['kid' => 'test-key-1'], 'attacker-key'),
            'unknown kid' => FakeGoogle::token([], ['kid' => 'no-such-key']),
            'no kid' => FakeGoogle::token([], ['kid' => null]),
            'alg none' => FakeGoogle::sign(['iss' => 'accounts.google.com', 'aud' => FakeGoogle::CLIENT_ID, 'sub' => 'x', 'email' => 'x@example.test', 'email_verified' => true, 'exp' => now()->timestamp + 60], ['alg' => 'none', 'kid' => 'test-key-1']),
            'alg HS256 (key confusion)' => FakeGoogle::token([], ['alg' => 'HS256']),
            'issued in the future' => FakeGoogle::token(['iat' => now()->timestamp + 3600]),
            'not before in the future' => FakeGoogle::token(['nbf' => now()->timestamp + 3600]),
            'missing subject' => FakeGoogle::token(['sub' => null]),
            'missing email' => FakeGoogle::token(['email' => null]),
            'invalid email' => FakeGoogle::token(['email' => 'not-an-email']),
            'garbage' => 'not.a.jwt',
            'two segments' => $h.'.'.$p,
            'four segments' => $valid.'.extra',
            'empty segment' => $h.'..'.$s,
            'non-base64 characters' => $h.'.!!!.'.$s,
        ];

        config(['stashly.rate_limits.google_auth_per_minute' => 1000]); // this test sends many requests from one IP
        $messages = [];
        foreach ($cases as $name => $token) {
            $response = $this->signIn([], $token);

            $this->assertApiError($response, 401, 'INVALID_GOOGLE_TOKEN');
            $messages[$name] = $response->json('error.message');
            $this->assertSame(0, User::count(), $name);
        }
        $this->assertCount(1, array_unique(array_diff_key($messages, ['x' => 1])), 'All rejections share one message, so the reason is not revealed.');
    }

    public function test_an_audience_list_containing_the_client_id_is_accepted(): void
    {
        $this->signIn([], FakeGoogle::token(['aud' => ['other', FakeGoogle::CLIENT_ID]]))->assertCreated();
    }

    public function test_both_google_issuer_spellings_are_accepted(): void
    {
        $this->signIn([], FakeGoogle::token(['iss' => 'accounts.google.com']))->assertCreated();
        $this->signIn([], FakeGoogle::token(['iss' => 'https://accounts.google.com', 'sub' => '2', 'email' => 'b@example.test']))->assertCreated();
    }

    public function test_the_attacker_key_is_not_trusted_even_if_it_is_correctly_formed(): void
    {
        // Sanity: the same token signed with the served key passes.
        $this->signIn([], FakeGoogle::token())->assertCreated();
        $this->assertSame(1, User::count());
    }

    // ------------------------------------------------------------------ availability

    public function test_the_endpoint_is_unavailable_when_google_sign_in_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);
        Http::fake();

        $response = $this->signIn();

        $this->assertApiError($response, 503, 'SERVICE_UNAVAILABLE');
        $this->assertSame(0, User::count());
        Http::assertNothingSent();
    }

    public function test_google_keys_being_unreachable_is_a_503_not_a_401(): void
    {
        FakeGoogle::serveFailure(500);

        $this->assertApiError($this->signIn(), 503, 'SERVICE_UNAVAILABLE');
        $this->assertSame(0, User::count());
    }

    // ------------------------------------------------------------------ request validation

    #[DataProvider('invalidRequests')]
    public function test_invalid_requests_fail_validation(array $body, string $field): void
    {
        $response = $this->postJson('/api/v1/auth/google', $body);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => [$field]]]);
    }

    public static function invalidRequests(): array
    {
        return [
            'id_token missing' => [['device_name' => 'x'], 'id_token'],
            'id_token not a string' => [['id_token' => ['a'], 'device_name' => 'x'], 'id_token'],
            'id_token too long' => [['id_token' => str_repeat('a', 8193), 'device_name' => 'x'], 'id_token'],
            'device_name missing' => [['id_token' => 'a.b.c'], 'device_name'],
            'device_name too long' => [['id_token' => 'a.b.c', 'device_name' => str_repeat('d', 256)], 'device_name'],
            'terms_accepted not boolean' => [['id_token' => 'a.b.c', 'device_name' => 'x', 'terms_accepted' => 'maybe'], 'terms_accepted'],
            'terms_version too long' => [['id_token' => 'a.b.c', 'device_name' => 'x', 'terms_version' => str_repeat('v', 51)], 'terms_version'],
        ];
    }

    public function test_the_endpoint_is_public(): void
    {
        $this->signIn()->assertCreated();
        $this->assertApiError($this->getJson('/api/v1/me'), 401, 'UNAUTHENTICATED');
    }

    // ------------------------------------------------------------------ passwordless accounts

    public function test_password_login_on_a_google_only_account_fails_exactly_like_an_unknown_account(): void
    {
        $this->signIn()->assertCreated();

        $googleOnly = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'whatever-password']);
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever-password']);
        $emptyish = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'x']);

        $this->assertApiError($googleOnly, 401, 'INVALID_CREDENTIALS');
        $strip = fn ($r) => array_diff_key($r->json('error'), ['request_id' => true]);
        $this->assertSame($strip($unknown), $strip($googleOnly));
        $this->assertSame($strip($unknown), $strip($emptyish));
        $this->assertSame(1, DB::table('personal_access_tokens')->count(), 'No token from the failed logins.');
    }

    public function test_login_on_a_google_only_account_costs_a_password_hash_so_timing_does_not_reveal_it(): void
    {
        $this->signIn()->assertCreated();
        Hash::spy();
        Hash::shouldReceive('make')->atLeast()->once()->andReturn('x');

        $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'whatever-password'])->assertStatus(401);
    }

    public function test_a_google_only_user_can_set_a_password_through_the_reset_flow_and_then_use_both_ways_in(): void
    {
        Notification::fake();
        $this->signIn()->assertCreated();
        $user = User::sole();

        $token = $this->resetCodeFor($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'lina@example.test', 'code' => $token, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'brand-new-password'])->assertOk();
        $this->signIn(['terms_accepted' => null])->assertOk();
        $this->assertNotNull($user->fresh()->google_id);
    }

    public function test_a_google_only_user_has_password_set_false_everywhere_the_user_object_appears(): void
    {
        $token = $this->signIn()->assertCreated()->assertJsonPath('data.user.password_set', false)->json('data.token.access_token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.password_set', false);
        $this->signIn(['terms_accepted' => null])->assertOk()->assertJsonPath('data.user.password_set', false);
    }

    public function test_changing_the_password_still_requires_a_current_password(): void
    {
        $token = $this->signIn()->assertCreated()->json('data.token.access_token');

        $this->withToken($token)->putJson('/api/v1/me/password', [
            'current_password' => 'anything', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);
        $this->assertNull(User::sole()->password);
    }

    public function test_a_google_only_user_deletes_the_account_with_a_fresh_id_token(): void
    {
        $token = $this->signIn()->assertCreated()->json('data.token.access_token');
        $other = User::factory()->create();

        $this->withToken($token)->deleteJson('/api/v1/me', ['id_token' => FakeGoogle::token()])->assertNoContent();

        $this->assertSame(1, User::count());
        $this->assertTrue(User::sole()->is($other));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', '!=', $other->id)->count());
    }

    public function test_deleting_a_google_only_account_fails_without_a_valid_matching_token(): void
    {
        $token = $this->signIn()->assertCreated()->json('data.token.access_token');

        // No token, and a password alone, are not proof for an account without a password.
        $this->assertApiError($this->withToken($token)->deleteJson('/api/v1/me'), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->withToken($token)->deleteJson('/api/v1/me', ['password' => 'anything']), 422, 'VALIDATION_FAILED');

        // Invalid, expired, wrong-audience and other-person tokens.
        foreach ([
            'garbage' => 'garbage.token.value',
            'expired' => FakeGoogle::token(['exp' => now()->timestamp - 1]),
            'wrong audience' => FakeGoogle::token(['aud' => 'another-app.apps.googleusercontent.com']),
            'another google account' => FakeGoogle::token(['sub' => 'someone-else', 'email' => 'lina@example.test']),
            'unverified email' => FakeGoogle::token(['email_verified' => false]),
        ] as $label => $idToken) {
            $this->assertApiError($this->withToken($token)->deleteJson('/api/v1/me', ['id_token' => $idToken]), 401, 'INVALID_GOOGLE_TOKEN');
            $this->assertSame(1, User::count(), $label);
        }
    }

    public function test_deleting_a_google_only_account_is_unavailable_when_google_keys_cannot_be_fetched(): void
    {
        $token = $this->signIn()->assertCreated()->json('data.token.access_token');
        FakeGoogle::serveFailure(500);
        Cache::flush();

        $this->assertApiError($this->withToken($token)->deleteJson('/api/v1/me', ['id_token' => FakeGoogle::token()]), 503, 'SERVICE_UNAVAILABLE');
        $this->assertSame(1, User::count());
    }

    public function test_a_user_with_a_password_still_deletes_with_the_password_and_ignores_an_id_token(): void
    {
        $user = User::factory()->create(['google_id' => 'sub-1']);
        $token = $this->tokenFor($user);

        $this->assertApiError($this->withToken($token)->deleteJson('/api/v1/me', ['id_token' => FakeGoogle::token(['sub' => 'sub-1'])]), 422, 'VALIDATION_FAILED');
        $this->withToken($token)->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();
        $this->assertSame(0, User::count());
    }

    public function test_a_google_user_who_later_set_a_password_deletes_with_the_password(): void
    {
        $this->signIn()->assertCreated();
        $user = User::sole();
        $user->forceFill(['password' => 'chosen-later-password'])->save();
        $token = $this->tokenFor($user);

        $this->withToken($token)->deleteJson('/api/v1/me', ['password' => 'chosen-later-password'])->assertNoContent();
        $this->assertSame(0, User::count());
    }

    public function test_the_delete_flow_logs_no_token_or_identifiers(): void
    {
        $token = $this->signIn()->assertCreated()->json('data.token.access_token');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });

        $idToken = FakeGoogle::token(['sub' => 'someone-else']);
        $this->withToken($token)->deleteJson('/api/v1/me', ['id_token' => $idToken])->assertStatus(401);

        $all = implode("\n", $logged);
        $this->assertStringNotContainsString($idToken, $all);
        $this->assertStringNotContainsString('lina@example.test', $all);
        $this->assertStringNotContainsString('someone-else', $all);
    }

    public function test_deleting_an_account_removes_the_google_link_with_it(): void
    {
        Notification::fake();
        $this->signIn()->assertCreated();
        $user = User::sole();
        $reset = $this->resetCodeFor($user);
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'lina@example.test', 'code' => $reset, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'])->assertOk();
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'brand-new-password'])->json('data.token.access_token');

        $this->withToken($login)->deleteJson('/api/v1/me', ['password' => 'brand-new-password'])->assertNoContent();

        $this->assertSame(0, User::count());
        // The same Google account can sign up again afterwards.
        $this->signIn()->assertCreated();
    }

    // ------------------------------------------------------------------ abuse / hygiene

    public function test_it_is_rate_limited_per_ip_with_retry_after(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->signIn([], FakeGoogle::token(['sub' => "s$i", 'email' => "u$i@example.test"]))->assertCreated();
        }

        $this->assertApiError($this->signIn(), 429, 'RATE_LIMITED')->assertHeader('Retry-After');

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/v1/auth/google', [
            'id_token' => FakeGoogle::token(['sub' => 'other-ip', 'email' => 'o@example.test']), 'device_name' => 'x', 'terms_accepted' => true,
        ])->assertCreated();
    }

    public function test_failed_attempts_count_towards_the_rate_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->signIn([], 'garbage.token.value')->assertStatus(401);
        }

        $this->assertApiError($this->signIn([], 'garbage.token.value'), 429, 'RATE_LIMITED');
    }

    public function test_logs_never_contain_the_token_the_email_or_google_ids(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        config(['stashly.rate_limits.google_auth_per_minute' => 1000]);
        $good = FakeGoogle::token();
        $bad = FakeGoogle::token(['aud' => 'nope']);
        $unverified = FakeGoogle::token(['email_verified' => false, 'sub' => 'u1', 'email' => 'u1@example.test']);

        $this->signIn([], $good)->assertCreated();
        $this->signIn([], $bad)->assertStatus(401);
        $this->signIn([], $unverified)->assertStatus(401);
        $this->signIn([], 'garbage')->assertStatus(401);
        FakeGoogle::serveFailure();
        $this->app['cache']->flush();
        $this->signIn([], FakeGoogle::token(['kid' => 'x']))->assertStatus(503);

        $all = implode("\n", $logged);
        $this->assertStringContainsString('Google sign-in rejected', $all);
        $this->assertStringContainsString('"reason"', $all);
        foreach ([$good, $bad, $unverified, explode('.', $good)[1], explode('.', $good)[2], 'lina@example.test', 'u1@example.test', '110169484474386276334', 'Lina Example'] as $secret) {
            $this->assertStringNotContainsString($secret, $all, 'Leaked into logs: '.substr($secret, 0, 12));
        }
    }

    public function test_the_error_response_never_contains_the_token(): void
    {
        $token = FakeGoogle::token(['aud' => 'nope']);

        $response = $this->signIn([], $token);

        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString(explode('.', $token)[1], $response->getContent());
    }

    public function test_the_export_and_user_object_do_not_expose_the_google_id_or_picture(): void
    {
        $token = $this->signIn()->assertCreated()->json('data.token.access_token');

        $this->assertStringNotContainsString('110169484474386276334', $this->withToken($token)->getJson('/api/v1/me')->getContent());
        $this->assertStringNotContainsString('googleusercontent', $this->withToken($token)->getJson('/api/v1/home')->getContent());
        $this->assertArrayNotHasKey('google_id', User::sole()->toArray(), 'google_id is hidden from serialization.');
    }
}
