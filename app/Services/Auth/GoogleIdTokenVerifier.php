<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies a Google ID token (a signed JWT) for POST /auth/google.
 *
 * Accepted only if ALL hold: RS256 (never "none"/HS256) signature that verifies against a
 * key in Google's published key set (matched by "kid"); iss is accounts.google.com;
 * aud is the configured client id; not expired; email present and email_verified is true;
 * sub present. Nothing from the token is trusted before the signature verifies.
 *
 * Google's keys are cached (honouring max-age); an unknown "kid" triggers at most one
 * refresh per minute so garbage tokens cannot make us hammer Google. The token itself is
 * never logged or put into an exception message.
 */
class GoogleIdTokenVerifier
{
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    private const CACHE_KEY = 'google:jwks';

    /** Minimum seconds between refreshes triggered by an unknown kid. */
    private const REFRESH_COOLDOWN = 60;

    /** Tolerated clock difference for iat/nbf. exp is strict. */
    private const LEEWAY = 60;

    public function isEnabled(): bool
    {
        return filled(config('services.google.client_id'));
    }

    /**
     * @throws GoogleTokenException
     */
    public function verify(string $idToken): GoogleIdentity
    {
        $clientId = config('services.google.client_id');
        if (! filled($clientId)) {
            throw new GoogleTokenException(GoogleTokenException::NOT_CONFIGURED);
        }

        [$header, $payload, $signature, $signingInput] = $this->parse($idToken);

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new GoogleTokenException(GoogleTokenException::ALGORITHM);
        }
        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '') {
            throw new GoogleTokenException(GoogleTokenException::UNKNOWN_KEY);
        }

        $key = $this->publicKey($kid);
        if (openssl_verify($signingInput, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new GoogleTokenException(GoogleTokenException::SIGNATURE);
        }

        return $this->identityFromClaims($payload, (string) $clientId);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function parse(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || in_array('', $parts, true) || strlen($token) > 8192) {
            throw new GoogleTokenException(GoogleTokenException::MALFORMED);
        }

        $header = $this->decodeJson($parts[0]);
        $payload = $this->decodeJson($parts[1]);
        $signature = self::base64UrlDecode($parts[2]);

        if ($header === null || $payload === null || $signature === null) {
            throw new GoogleTokenException(GoogleTokenException::MALFORMED);
        }

        return [$header, $payload, $signature, $parts[0].'.'.$parts[1]];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function identityFromClaims(array $claims, string $clientId): GoogleIdentity
    {
        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new GoogleTokenException(GoogleTokenException::ISSUER);
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        if (! in_array($clientId, $audiences, true)) {
            throw new GoogleTokenException(GoogleTokenException::AUDIENCE);
        }

        $now = now()->timestamp;
        $exp = $claims['exp'] ?? null;
        if (! is_int($exp) || $exp <= $now) {
            throw new GoogleTokenException(GoogleTokenException::EXPIRED);
        }
        foreach (['iat', 'nbf'] as $claim) {
            if (isset($claims[$claim]) && (! is_int($claims[$claim]) || $claims[$claim] > $now + self::LEEWAY)) {
                throw new GoogleTokenException(GoogleTokenException::NOT_YET_VALID);
            }
        }

        $subject = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (! is_string($subject) || $subject === '' || strlen($subject) > 255
            || ! is_string($email) || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new GoogleTokenException(GoogleTokenException::CLAIMS);
        }
        if (($claims['email_verified'] ?? null) !== true) {
            throw new GoogleTokenException(GoogleTokenException::EMAIL_UNVERIFIED);
        }

        $picture = $claims['picture'] ?? null;
        $name = $claims['name'] ?? null;

        return new GoogleIdentity(
            subject: $subject,
            email: mb_strtolower(trim($email)),
            name: is_string($name) ? $name : null,
            picture: is_string($picture) && strlen($picture) <= 2048 && str_starts_with($picture, 'https://') && filter_var($picture, FILTER_VALIDATE_URL) !== false
                ? $picture : null,
        );
    }

    // ------------------------------------------------------------------ keys

    /**
     * PEM public key for the given key id.
     *
     * @throws GoogleTokenException
     */
    private function publicKey(string $kid): string
    {
        $cached = Cache::get(self::CACHE_KEY);
        $now = now()->timestamp;

        $expired = ! is_array($cached) || ($cached['expires_at'] ?? 0) <= $now;
        $unknownKid = is_array($cached) && ! isset($cached['keys'][$kid]);
        $mayRefreshForKid = is_array($cached) && ($now - ($cached['fetched_at'] ?? 0)) >= self::REFRESH_COOLDOWN;

        if ($expired || ($unknownKid && $mayRefreshForKid)) {
            $fetched = $this->fetchKeys();
            if ($fetched !== null) {
                $cached = $fetched;
            } elseif (! is_array($cached)) {
                throw new GoogleTokenException(GoogleTokenException::KEYS_UNAVAILABLE);
            }
            // else: Google is unreachable but a (stale) key set exists; keep using it.
        }

        $jwk = $cached['keys'][$kid] ?? null;
        if ($jwk === null) {
            throw new GoogleTokenException(GoogleTokenException::UNKNOWN_KEY);
        }

        $pem = self::jwkToPem($jwk);
        if ($pem === null) {
            throw new GoogleTokenException(GoogleTokenException::UNKNOWN_KEY);
        }

        return $pem;
    }

    /**
     * @return array{keys: array<string, array<string, mixed>>, fetched_at: int, expires_at: int}|null
     */
    private function fetchKeys(): ?array
    {
        try {
            $response = Http::timeout(5)->connectTimeout(3)->acceptJson()->get(config('services.google.jwks_url'));
        } catch (Throwable) {
            Log::warning('Google key set could not be fetched.', ['reason' => 'request_failed']);

            return null;
        }

        $keys = [];
        foreach ((array) $response->json('keys') as $jwk) {
            if (is_array($jwk) && is_string($jwk['kid'] ?? null) && ($jwk['kty'] ?? null) === 'RSA') {
                $keys[$jwk['kid']] = $jwk;
            }
        }
        if (! $response->successful() || $keys === []) {
            Log::warning('Google key set was not usable.', ['status' => $response->status()]);

            return null;
        }

        $maxAge = 3600;
        if (preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $m)) {
            $maxAge = min(max((int) $m[1], 300), 86400);
        }
        $now = now()->timestamp;
        $set = ['keys' => $keys, 'fetched_at' => $now, 'expires_at' => $now + $maxAge];
        // Keep the set for a day beyond its freshness so it can serve as a fallback if Google is down.
        Cache::put(self::CACHE_KEY, $set, 86400 + $maxAge);

        return $set;
    }

    // ------------------------------------------------------------------ encoding helpers

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $segment): ?array
    {
        $json = self::base64UrlDecode($segment);
        $value = $json === null ? null : json_decode($json, true);

        return is_array($value) && ! array_is_list($value) ? $value : null;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Builds a PEM "SubjectPublicKeyInfo" from a JWK's modulus (n) and exponent (e).
     *
     * @param  array<string, mixed>  $jwk
     */
    public static function jwkToPem(array $jwk): ?string
    {
        $n = is_string($jwk['n'] ?? null) ? self::base64UrlDecode($jwk['n']) : null;
        $e = is_string($jwk['e'] ?? null) ? self::base64UrlDecode($jwk['e']) : null;
        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }

        $integer = fn (string $bytes) => "\x02".self::derLength(strlen($bytes) + (ord($bytes[0]) > 0x7F ? 1 : 0))
            .(ord($bytes[0]) > 0x7F ? "\x00" : '').$bytes;
        $sequence = fn (string $content) => "\x30".self::derLength(strlen($content)).$content;

        $rsaPublicKey = $sequence($integer($n).$integer($e));
        $algorithm = $sequence("\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00");
        $bitString = "\x03".self::derLength(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;
        $der = $sequence($algorithm.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
