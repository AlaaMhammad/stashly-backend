<?php

namespace App\Services\Links;

/**
 * The single URL normalizer (contract 4.1). Used for preview, save and
 * duplicate detection, and to re-validate before any metadata fetch.
 *
 * Rules: trim; add https:// when no scheme; http/https only; no credentials;
 * max 2048 characters; lowercase scheme and host; drop www. / m.; remove
 * default ports, fragments and tracking parameters; sort remaining query
 * parameters; strip trailing slashes.
 *
 * Provisional details are recorded in DEC-004 (tracking list) and DEC-026.
 */
class UrlNormalizer
{
    /** Exact tracking parameter names (DEC-004); utm_* is matched by prefix. */
    private const TRACKING_PARAMETERS = [
        'fbclid', 'gclid', 'dclid', 'gbraid', 'wbraid', 'msclkid', 'twclid',
        'ttclid', 'igshid', 'yclid', 'mc_cid', 'mc_eid', '_ga', '_gl',
    ];

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function __construct(private readonly int $maxLength = 2048) {}

    /**
     * @throws InvalidUrl
     */
    public function normalize(string $input): NormalizedUrl
    {
        if (! mb_check_encoding($input, 'UTF-8')) {
            throw InvalidUrl::because(InvalidUrl::INVALID_CHARACTERS);
        }

        // Trim ASCII and Unicode spaces (shared text often carries NBSP or zero-width spaces).
        $original = preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', $input);

        if ($original === '') {
            throw InvalidUrl::because(InvalidUrl::EMPTY);
        }
        if (mb_strlen($original) > $this->maxLength) {
            throw InvalidUrl::because(InvalidUrl::TOO_LONG);
        }
        // Inner whitespace, control characters and backslashes (which browsers read as "/").
        if (preg_match('/[\s\x{00A0}\x{200B}\x{FEFF}\x00-\x1F\x7F\\\\]/u', $original)) {
            throw InvalidUrl::because(InvalidUrl::INVALID_CHARACTERS);
        }

        $url = $this->withScheme($original);
        $scheme = strtolower(strstr($url, ':', true));

        if (! array_key_exists($scheme, self::DEFAULT_PORTS)) {
            throw InvalidUrl::because(InvalidUrl::UNSUPPORTED_SCHEME);
        }
        if (! str_starts_with(substr($url, strlen($scheme)), '://')) {
            throw InvalidUrl::because(InvalidUrl::INVALID_HOST);
        }

        $rest = substr($url, strlen($scheme) + 3);
        $authorityLength = strcspn($rest, '/?#');
        $authority = substr($rest, 0, $authorityLength);
        $remainder = substr($rest, $authorityLength);

        if (str_contains($authority, '@')) {
            throw InvalidUrl::because(InvalidUrl::CREDENTIALS);
        }

        [$host, $port] = $this->parseAuthority($authority, $scheme);
        [$fetchHost] = $this->parseAuthority($authority, $scheme, stripPrefix: false);

        $fragmentAt = strpos($remainder, '#');
        if ($fragmentAt !== false) {
            $remainder = substr($remainder, 0, $fragmentAt);
        }
        [$path, $query] = array_pad(explode('?', $remainder, 2), 2, '');

        $suffix = ($port !== null ? ':'.$port : '')
            .$this->normalizeEncoding(rtrim($path, '/'))
            .$this->normalizeQuery($query);
        $normalized = $scheme.'://'.$host.$suffix;
        $fetchUrl = $scheme.'://'.$fetchHost.$suffix;

        if (mb_strlen($normalized) > $this->maxLength) {
            throw InvalidUrl::because(InvalidUrl::TOO_LONG);
        }

        return new NormalizedUrl($original, $normalized, $host, $fetchUrl);
    }

    private function withScheme(string $url): string
    {
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        $looksLikeScheme = preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url) === 1;
        $looksLikeHostAndPort = preg_match('/^[^:\/?#]+:\d+(?:[\/?#]|$)/', $url) === 1;

        return ! $looksLikeScheme || $looksLikeHostAndPort ? 'https://'.$url : $url;
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function parseAuthority(string $authority, string $scheme, bool $stripPrefix = true): array
    {
        if (preg_match('/^\[([0-9a-f:.]+)\](?::(\d*))?$/i', $authority, $m)) {
            if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw InvalidUrl::because(InvalidUrl::INVALID_HOST);
            }
            $host = '['.strtolower($m[1]).']';
        } elseif (preg_match('/^([^:\[\]]+)(?::(\d*))?$/u', $authority, $m)) {
            $host = $this->normalizeHost($m[1], $stripPrefix);
        } else {
            throw InvalidUrl::because(InvalidUrl::INVALID_HOST);
        }

        $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw InvalidUrl::because(InvalidUrl::INVALID_PORT);
        }
        if ($port === self::DEFAULT_PORTS[$scheme]) {
            $port = null;
        }

        return [$host, $port];
    }

    private function normalizeHost(string $host, bool $stripPrefix = true): string
    {
        $host = rtrim(mb_strtolower($host), '.');

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        // Hostnames need at least two labels and a non-numeric last label. This
        // rejects "localhost" and shorthand IPv4 such as "127.1" or "0x7f.1".
        $labels = explode('.', $host);
        $label = '/^[\p{L}\p{N}_](?:[\p{L}\p{N}_\-]{0,61}[\p{L}\p{N}_])?$/u';

        if (count($labels) < 2 || strlen($host) > 253 || ctype_digit(end($labels))) {
            throw InvalidUrl::because(InvalidUrl::INVALID_HOST);
        }
        foreach ($labels as $part) {
            if (! preg_match($label, $part)) {
                throw InvalidUrl::because(InvalidUrl::INVALID_HOST);
            }
        }

        // Drop one leading www. or m., but never down to a bare suffix ("m.me" stays).
        if ($stripPrefix && preg_match('/^(?:www|m)\.(.+)$/u', $host, $m) && str_contains($m[1], '.')) {
            $host = $m[1];
        }

        return $host;
    }

    private function normalizeQuery(string $query): string
    {
        $pairs = array_values(array_filter(explode('&', $query), function (string $pair) {
            if ($pair === '') {
                return false;
            }
            $key = strtolower(rawurldecode(strstr($pair, '=', true) ?: $pair));

            return ! str_starts_with($key, 'utm_') && ! in_array($key, self::TRACKING_PARAMETERS, true);
        }));

        // Stable sort by decoded key keeps repeated keys (a=1&a=2) in their original order.
        usort($pairs, fn (string $a, string $b) => strcmp(
            rawurldecode(strstr($a, '=', true) ?: $a),
            rawurldecode(strstr($b, '=', true) ?: $b),
        ));

        return $pairs === [] ? '' : '?'.implode('&', array_map($this->normalizeEncoding(...), $pairs));
    }

    /**
     * Percent-encode raw non-ASCII characters and uppercase existing escapes, so
     * a raw Arabic path and its percent-encoded form normalize identically.
     */
    private function normalizeEncoding(string $value): string
    {
        $value = preg_replace_callback('/[^\x00-\x7F]+/', fn ($m) => rawurlencode($m[0]), $value);

        return preg_replace_callback('/%[0-9a-f]{2}/i', fn ($m) => strtoupper($m[0]), $value);
    }
}
