<?php

namespace App\Support\Api;

/**
 * Opaque, signed pagination cursor. The HMAC (keyed by APP_KEY) covers the
 * payload plus a binding string (user and filters), so a cursor that was
 * edited, is malformed, or is reused by another user or with other filters
 * fails to decode and is answered with INVALID_CURSOR.
 */
final class Cursor
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload, string $binding): string
    {
        $body = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        return $body.'.'.self::signature($body, $binding);
    }

    /**
     * @return array<string, mixed>|null null when malformed, tampered or bound to something else
     */
    public static function decode(string $cursor, string $binding): ?array
    {
        $parts = explode('.', $cursor);

        if (count($parts) !== 2 || ! hash_equals(self::signature($parts[0], $binding), $parts[1])) {
            return null;
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private static function signature(string $body, string $binding): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $body.'|'.$binding, config('app.key'), true));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
