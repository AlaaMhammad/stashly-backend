<?php

namespace Tests\Feature\Auth;

use App\Services\Auth\GoogleIdentity;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\Auth\GoogleTokenException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeGoogle;
use Tests\TestCase;

/**
 * GoogleIdTokenVerifier in isolation (the HTTP endpoint is covered by GoogleAuthTest).
 */
class GoogleIdTokenVerifierTest extends TestCase
{
    private GoogleIdTokenVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        FakeGoogle::install();
        $this->verifier = app(GoogleIdTokenVerifier::class);
    }

    private function assertRejected(string $reason, string $token): void
    {
        try {
            $this->verifier->verify($token);
            $this->fail("Expected rejection ($reason).");
        } catch (GoogleTokenException $e) {
            $this->assertSame($reason, $e->reason);
            if ($token !== '') {
                $this->assertStringNotContainsString($token, $e->getMessage(), 'The token must never appear in an exception message.');
            }
        }
    }

    public function test_a_valid_token_yields_the_verified_identity(): void
    {
        $identity = $this->verifier->verify(FakeGoogle::token(['email' => 'Lina@Example.TEST', 'name' => 'Lina']));

        $this->assertInstanceOf(GoogleIdentity::class, $identity);
        $this->assertSame('110169484474386276334', $identity->subject);
        $this->assertSame('lina@example.test', $identity->email);
        $this->assertSame('Lina', $identity->name);
        $this->assertSame('https://lh3.googleusercontent.com/a/photo=s96-c', $identity->picture);
    }

    public function test_it_reports_whether_google_sign_in_is_enabled(): void
    {
        $this->assertTrue($this->verifier->isEnabled());

        config(['services.google.client_id' => null]);
        $this->assertFalse($this->verifier->isEnabled());
        config(['services.google.client_id' => '']);
        $this->assertFalse($this->verifier->isEnabled());
    }

    public function test_without_a_configured_client_id_nothing_is_verified_and_nothing_is_fetched(): void
    {
        config(['services.google.client_id' => null]);

        $this->assertRejected(GoogleTokenException::NOT_CONFIGURED, FakeGoogle::token());
        $this->assertSame(0, FakeGoogle::keyFetches());
    }

    #[DataProvider('rejections')]
    public function test_rejects_with_a_specific_internal_reason(string $reason, array $claims, array $header): void
    {
        $this->assertRejected($reason, FakeGoogle::token($claims, $header));
    }

    public static function rejections(): array
    {
        return [
            'wrong issuer' => [GoogleTokenException::ISSUER, ['iss' => 'https://accounts.google.com.evil.example'], []],
            'issuer missing' => [GoogleTokenException::ISSUER, ['iss' => null], []],
            'wrong audience' => [GoogleTokenException::AUDIENCE, ['aud' => 'x'], []],
            'audience missing' => [GoogleTokenException::AUDIENCE, ['aud' => null], []],
            'audience list without the client' => [GoogleTokenException::AUDIENCE, ['aud' => ['a', 'b']], []],
            'audience prefix of the client id' => [GoogleTokenException::AUDIENCE, ['aud' => '1234567890-testclient'], []],
            'expired' => [GoogleTokenException::EXPIRED, ['exp' => 1], []],
            'exp not an integer' => [GoogleTokenException::EXPIRED, ['exp' => '9999999999'], []],
            'exp missing' => [GoogleTokenException::EXPIRED, ['exp' => null], []],
            'iat far in the future' => [GoogleTokenException::NOT_YET_VALID, ['iat' => 4102444800], []],
            'subject empty' => [GoogleTokenException::CLAIMS, ['sub' => ''], []],
            'subject not a string' => [GoogleTokenException::CLAIMS, ['sub' => 12345], []],
            'email missing' => [GoogleTokenException::CLAIMS, ['email' => null], []],
            'email malformed' => [GoogleTokenException::CLAIMS, ['email' => 'a@@b'], []],
            'email over 255 characters' => [GoogleTokenException::CLAIMS, ['email' => str_repeat('a', 250).'@example.test'], []],
            'email not verified' => [GoogleTokenException::EMAIL_UNVERIFIED, ['email_verified' => false], []],
            'email_verified missing' => [GoogleTokenException::EMAIL_UNVERIFIED, ['email_verified' => null], []],
            'email_verified as a string' => [GoogleTokenException::EMAIL_UNVERIFIED, ['email_verified' => 'true'], []],
            'algorithm HS256' => [GoogleTokenException::ALGORITHM, [], ['alg' => 'HS256']],
            'algorithm none' => [GoogleTokenException::ALGORITHM, [], ['alg' => 'none']],
            'algorithm RS512' => [GoogleTokenException::ALGORITHM, [], ['alg' => 'RS512']],
            'algorithm missing' => [GoogleTokenException::ALGORITHM, [], ['alg' => null]],
            'kid missing' => [GoogleTokenException::UNKNOWN_KEY, [], ['kid' => null]],
            'kid not a string' => [GoogleTokenException::UNKNOWN_KEY, [], ['kid' => 7]],
            'kid unknown' => [GoogleTokenException::UNKNOWN_KEY, [], ['kid' => 'rotated-away']],
        ];
    }

    #[DataProvider('malformed')]
    public function test_rejects_malformed_tokens(string $token): void
    {
        $this->assertRejected(GoogleTokenException::MALFORMED, $token);
    }

    public static function malformed(): array
    {
        $b64 = fn (array $v) => FakeGoogle::b64(json_encode($v));

        return [
            'empty' => [''],
            'one segment' => ['abc'],
            'two segments' => ['a.b'],
            'four segments' => ['a.b.c.d'],
            'empty middle' => ['a..c'],
            'header not base64url' => ['!!!.'.$b64(['a' => 1]).'.sig'],
            'header not json' => [FakeGoogle::b64('not json').'.'.$b64(['a' => 1]).'.c2ln'],
            'header is a json list' => [FakeGoogle::b64('[1,2]').'.'.$b64(['a' => 1]).'.c2ln'],
            'payload is a scalar' => [$b64(['alg' => 'RS256']).'.'.FakeGoogle::b64('"text"').'.c2ln'],
            'oversized' => [str_repeat('a', 9000).'.b.c'],
        ];
    }

    public function test_a_signature_from_a_different_key_is_rejected_even_when_the_kid_matches(): void
    {
        $this->assertRejected(GoogleTokenException::SIGNATURE, FakeGoogle::token([], ['kid' => 'test-key-1'], 'attacker-key'));
    }

    public function test_flipping_one_bit_of_the_signature_is_rejected(): void
    {
        [$h, $p, $s] = explode('.', FakeGoogle::token());
        $bytes = base64_decode(strtr($s, '-_', '+/').'==', true);
        $bytes[10] = chr(ord($bytes[10]) ^ 1);

        $this->assertRejected(GoogleTokenException::SIGNATURE, "$h.$p.".FakeGoogle::b64($bytes));
    }

    public function test_the_unsigned_algorithm_is_never_accepted_even_with_an_empty_signature_segment_trick(): void
    {
        $unsigned = FakeGoogle::b64(json_encode(['alg' => 'none', 'kid' => 'test-key-1'])).'.'.FakeGoogle::b64(json_encode(['iss' => 'accounts.google.com'])).'.QQ';

        $this->assertRejected(GoogleTokenException::ALGORITHM, $unsigned);
    }

    public function test_iat_and_nbf_tolerate_a_small_clock_difference_but_exp_is_strict(): void
    {
        $now = now()->timestamp;
        $this->travelTo(now()->startOfSecond());

        $this->verifier->verify(FakeGoogle::token(['iat' => $now + 30, 'nbf' => $now + 30]));
        $this->assertRejected(GoogleTokenException::NOT_YET_VALID, FakeGoogle::token(['iat' => $now + 120]));
        $this->assertRejected(GoogleTokenException::EXPIRED, FakeGoogle::token(['exp' => $now]));
        $this->verifier->verify(FakeGoogle::token(['exp' => $now + 1]));
    }

    // ------------------------------------------------------------------ key conversion

    #[DataProvider('keySizes')]
    public function test_a_jwk_converts_to_exactly_the_same_public_key_openssl_exports(int $bits): void
    {
        $key = FakeGoogle::key("size-$bits", $bits);

        $pem = GoogleIdTokenVerifier::jwkToPem($key['jwk']);

        $resource = openssl_pkey_get_private($key['pem'], null);
        $expected = openssl_pkey_get_details($resource)['key'];
        $this->assertSame(preg_replace('/\s+/', '', $expected), preg_replace('/\s+/', '', (string) $pem));
        $this->assertNotFalse(openssl_pkey_get_public($pem), 'OpenSSL must accept the generated PEM.');
    }

    public static function keySizes(): array
    {
        return ['1024-bit (1-byte DER length form)' => [1024], '2048-bit (2-byte DER length form)' => [2048]];
    }

    public function test_incomplete_or_invalid_jwks_produce_no_key(): void
    {
        $this->assertNull(GoogleIdTokenVerifier::jwkToPem(['kty' => 'RSA', 'n' => 'abc']));
        $this->assertNull(GoogleIdTokenVerifier::jwkToPem(['kty' => 'RSA', 'e' => 'AQAB']));
        $this->assertNull(GoogleIdTokenVerifier::jwkToPem(['n' => '!!!', 'e' => 'AQAB']));
        $this->assertNull(GoogleIdTokenVerifier::jwkToPem(['n' => '', 'e' => '']));
        $this->assertNull(GoogleIdTokenVerifier::jwkToPem(['n' => ['x'], 'e' => 'AQAB']));
    }

    // ------------------------------------------------------------------ key set caching

    public function test_the_key_set_is_fetched_once_and_cached(): void
    {
        $this->verifier->verify(FakeGoogle::token());
        $this->verifier->verify(FakeGoogle::token(['sub' => '2']));
        $this->verifier->verify(FakeGoogle::token(['sub' => '3']));

        $this->assertSame(1, FakeGoogle::keyFetches());
    }

    public function test_the_key_set_is_refreshed_after_its_max_age(): void
    {
        FakeGoogle::serveKeys(['test-key-1'], maxAge: 600);
        $this->verifier->verify(FakeGoogle::token());

        $this->travel(599)->seconds();
        $this->verifier->verify(FakeGoogle::token());
        $this->assertSame(1, FakeGoogle::keyFetches());

        $this->travel(2)->seconds();
        $this->verifier->verify(FakeGoogle::token());
        $this->assertSame(2, FakeGoogle::keyFetches());
    }

    public function test_the_max_age_from_google_is_clamped_to_a_sane_range(): void
    {
        FakeGoogle::serveKeys(['test-key-1'], maxAge: 1);
        $this->verifier->verify(FakeGoogle::token());
        $this->travel(200)->seconds();
        $this->verifier->verify(FakeGoogle::token());
        $this->assertSame(1, FakeGoogle::keyFetches(), 'A 1-second max-age is raised to 5 minutes so Google is not hammered.');

        Cache::flush();
        FakeGoogle::serveKeys(['test-key-1'], maxAge: 99999999);
        $this->verifier->verify(FakeGoogle::token());
        $this->travel(86401)->seconds();
        $this->verifier->verify(FakeGoogle::token(['iat' => null, 'exp' => now()->timestamp + 3600]));
        $this->assertSame(2, FakeGoogle::keyFetches(), 'A huge max-age is capped at 24 hours: the initial fetch plus one refetch after a day.');
    }

    public function test_an_unknown_kid_does_not_make_us_refetch_more_than_once_a_minute(): void
    {
        $this->verifier->verify(FakeGoogle::token()); // fetch #1 (now cached, fetched_at = now)

        for ($i = 0; $i < 5; $i++) {
            $this->assertRejected(GoogleTokenException::UNKNOWN_KEY, FakeGoogle::token([], ['kid' => "random-$i"]));
        }
        $this->assertSame(1, FakeGoogle::keyFetches(), 'Garbage kids must not trigger refetches during the cooldown.');
    }

    public function test_a_rotated_key_is_picked_up_once_the_cooldown_has_passed(): void
    {
        $this->verifier->verify(FakeGoogle::token());
        FakeGoogle::serveKeys(['test-key-1', 'test-key-2']);

        $this->travel(61)->seconds();
        $identity = $this->verifier->verify(FakeGoogle::token([], ['kid' => 'test-key-2'], 'test-key-2'));

        $this->assertSame('110169484474386276334', $identity->subject);
        $this->assertSame(1, FakeGoogle::keyFetches(), 'Counted on the fresh client: exactly one refresh.');
    }

    public function test_when_google_is_unreachable_and_no_keys_are_cached_the_error_says_so(): void
    {
        FakeGoogle::serveFailure(500);

        $this->assertRejected(GoogleTokenException::KEYS_UNAVAILABLE, FakeGoogle::token());
    }

    public function test_a_malformed_key_response_is_treated_as_unavailable(): void
    {
        Http::swap(new Factory);
        Http::fake(['www.googleapis.com/*' => Http::response(['keys' => 'nope'], 200)]);

        $this->assertRejected(GoogleTokenException::KEYS_UNAVAILABLE, FakeGoogle::token());

        Http::swap(new Factory);
        Http::fake(['www.googleapis.com/*' => Http::response(['keys' => [['kid' => 'k', 'kty' => 'EC']]], 200)]);
        Cache::flush();
        $this->assertRejected(GoogleTokenException::KEYS_UNAVAILABLE, FakeGoogle::token());
    }

    public function test_stale_keys_keep_working_if_google_is_down_after_they_expire(): void
    {
        FakeGoogle::serveKeys(['test-key-1'], maxAge: 600);
        $this->verifier->verify(FakeGoogle::token());

        FakeGoogle::serveFailure(503);
        $this->travel(2)->hours();

        $identity = $this->verifier->verify(FakeGoogle::token());

        $this->assertSame('110169484474386276334', $identity->subject);
    }
}
