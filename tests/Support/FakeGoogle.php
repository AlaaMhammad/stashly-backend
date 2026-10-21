<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Test double for Google: real RSA keys, correctly signed RS256 ID tokens and a faked
 * key-set endpoint, so the real GoogleIdTokenVerifier is exercised end to end with no
 * network access.
 */
final class FakeGoogle
{
    public const CLIENT_ID = '1234567890-testclient.apps.googleusercontent.com';

    public const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /** @var array<string, array{pem: string, jwk: array<string, string>}> */
    private static array $keys = [];

    /**
     * A named key pair, generated once per test process.
     *
     * @return array{pem: string, jwk: array<string, string>}
     */
    public static function key(string $kid = 'test-key-1', int $bits = 2048): array
    {
        $cacheKey = "$kid:$bits";
        if (! isset(self::$keys[$cacheKey])) {
            $options = ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + self::opensslConfig();
            $resource = openssl_pkey_new($options);
            if ($resource === false) {
                throw new \RuntimeException('OpenSSL could not generate a test key: '.openssl_error_string());
            }
            openssl_pkey_export($resource, $pem, null, self::opensslConfig());
            $rsa = openssl_pkey_get_details($resource)['rsa'];
            self::$keys[$cacheKey] = ['pem' => $pem, 'jwk' => [
                'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid,
                'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e']),
            ]];
        }

        return self::$keys[$cacheKey];
    }

    /**
     * PHP on Windows (XAMPP) often cannot find openssl.cnf, which key generation needs.
     *
     * @return array<string, string>
     */
    private static function opensslConfig(): array
    {
        $candidates = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY).'/extras/ssl/openssl.cnf',
            dirname(PHP_BINARY, 2).'/apache/conf/openssl.cnf',
            '/etc/ssl/openssl.cnf',
        ]);
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return ['config' => $path];
            }
        }

        return [];
    }

    /**
     * Configure the client id and serve the given key ids from the faked key endpoint.
     *
     * @param  list<string>  $kids
     */
    public static function install(array $kids = ['test-key-1'], int $maxAge = 19000, ?string $clientId = self::CLIENT_ID): void
    {
        config(['services.google.client_id' => $clientId]);
        self::serveKeys($kids, $maxAge);
    }

    /**
     * @param  list<string>  $kids
     */
    public static function serveKeys(array $kids, int $maxAge = 19000): void
    {
        self::resetHttp();
        Http::fake([
            'www.googleapis.com/*' => Http::response(
                ['keys' => array_map(fn (string $kid) => self::key($kid)['jwk'], $kids)],
                200,
                ['Cache-Control' => "public, max-age=$maxAge, must-revalidate, no-transform"],
            ),
        ]);
    }

    public static function serveFailure(int $status = 500): void
    {
        self::resetHttp();
        Http::fake(['www.googleapis.com/*' => Http::response('error', $status)]);
    }

    /**
     * Http::fake() calls accumulate and the first matching stub wins, so start from a fresh client.
     */
    private static function resetHttp(): void
    {
        Http::swap(new Factory);
    }

    /**
     * Number of requests made to Google's key endpoint so far.
     */
    public static function keyFetches(): int
    {
        return Http::recorded(fn (Request $request) => $request->url() === self::JWKS_URL)->count();
    }

    /**
     * A correctly signed ID token. Pass claim overrides (null removes a claim).
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $header
     */
    public static function token(array $claims = [], array $header = [], string $signWith = 'test-key-1'): string
    {
        $now = now()->timestamp;
        $payload = array_filter(array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'azp' => self::CLIENT_ID,
            'sub' => '110169484474386276334',
            'email' => 'lina@example.test',
            'email_verified' => true,
            'name' => 'Lina Example',
            'picture' => 'https://lh3.googleusercontent.com/a/photo=s96-c',
            'iat' => $now - 10,
            'exp' => $now + 3600,
        ], $claims), fn ($value) => $value !== null);

        return self::sign($payload, array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'test-key-1'], $header), $signWith);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $header
     */
    public static function sign(array $payload, array $header, string $signWith = 'test-key-1'): string
    {
        $input = self::b64(json_encode(array_filter($header, fn ($v) => $v !== null))).'.'.self::b64(json_encode($payload));
        openssl_sign($input, $signature, self::key($signWith)['pem'], OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64($signature);
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
