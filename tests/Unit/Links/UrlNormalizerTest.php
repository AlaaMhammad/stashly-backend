<?php

namespace Tests\Unit\Links;

use App\Services\Links\InvalidUrl;
use App\Services\Links\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Normalization matrix for contract section 4.1.
 */
class UrlNormalizerTest extends TestCase
{
    private function normalize(string $url): string
    {
        return (new UrlNormalizer(2048))->normalize($url)->normalized;
    }

    #[DataProvider('normalizations')]
    public function test_normalizes(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalize($input));
    }

    public static function normalizations(): array
    {
        return [
            'adds https when scheme is missing' => ['example.com', 'https://example.com'],
            'trims surrounding spaces' => ['  https://example.com  ', 'https://example.com'],
            'trims unicode spaces' => ["\u{00A0}https://example.com\u{200B}", 'https://example.com'],
            'lowercases scheme and host, keeps path case' => ['HTTP://Example.COM/Path/To', 'http://example.com/Path/To'],
            'drops www.' => ['https://www.example.com/a', 'https://example.com/a'],
            'drops m.' => ['https://m.example.com/a', 'https://example.com/a'],
            'drops only one leading label' => ['https://www.m.example.com', 'https://m.example.com'],
            'keeps m. when it would leave a bare suffix' => ['https://m.me/page', 'https://m.me/page'],
            'removes https default port' => ['https://example.com:443/a', 'https://example.com/a'],
            'removes http default port' => ['http://example.com:80', 'http://example.com'],
            'keeps 443 on http (not its default)' => ['http://example.com:443', 'http://example.com:443'],
            'keeps non-default port' => ['https://example.com:8443/x', 'https://example.com:8443/x'],
            'host:port without scheme' => ['example.com:8080/path', 'https://example.com:8080/path'],
            'protocol-relative' => ['//example.com/a', 'https://example.com/a'],
            'removes fragment' => ['https://example.com/a#section', 'https://example.com/a'],
            'removes tracking parameters' => [
                'https://example.com/a?utm_source=x&id=5&UTM_Medium=y&fbclid=1&gclid=2&msclkid=3',
                'https://example.com/a?id=5',
            ],
            'sorts remaining parameters' => ['https://example.com/?b=2&a=1&c=3', 'https://example.com?a=1&b=2&c=3'],
            'keeps repeated keys in original order' => ['https://example.com/s?b=1&a=2&a=1', 'https://example.com/s?a=2&a=1&b=1'],
            'drops empty query when only tracking was present' => ['https://example.com/?utm_source=x', 'https://example.com'],
            'drops empty pairs' => ['https://example.com/x?&a=1&&', 'https://example.com/x?a=1'],
            'strips trailing slashes' => ['https://example.com/a//', 'https://example.com/a'],
            'strips root slash' => ['https://example.com/', 'https://example.com'],
            'uppercases percent escapes' => ['https://example.com/a%2fb?q=%e2%9c%93', 'https://example.com/a%2Fb?q=%E2%9C%93'],
            'encodes raw arabic path' => ['https://ar.wikipedia.org/wiki/مصر', 'https://ar.wikipedia.org/wiki/%D9%85%D8%B5%D8%B1'],
            'lowercases unicode host' => ['https://Bücher.example/', 'https://bücher.example'],
            'drops trailing dot of host' => ['https://example.com./a', 'https://example.com/a'],
            'accepts IPv4 host (blocked later at fetch)' => ['http://192.168.1.1/admin', 'http://192.168.1.1/admin'],
            'accepts IPv6 host' => ['http://[::1]:8080/', 'http://[::1]:8080'],
        ];
    }

    #[DataProvider('equivalentUrls')]
    public function test_equivalent_urls_share_one_normalized_form_and_hash(string $a, string $b): void
    {
        $normalizer = new UrlNormalizer(2048);

        $this->assertSame($normalizer->normalize($a)->hash(), $normalizer->normalize($b)->hash());
    }

    public static function equivalentUrls(): array
    {
        return [
            'www and tracking' => ['https://www.example.com/post/?utm_source=x#top', 'example.com/post'],
            'raw vs encoded arabic' => ['https://ar.wikipedia.org/wiki/مصر', 'https://ar.wikipedia.org/wiki/%d9%85%d8%b5%d8%b1'],
            'parameter order' => ['https://example.com/s?q=a&page=2', 'https://example.com/s?page=2&q=a'],
            'case of scheme and host' => ['HTTPS://EXAMPLE.COM/a', 'https://example.com/a'],
        ];
    }

    public function test_preserves_the_trimmed_original_and_reports_host(): void
    {
        $url = (new UrlNormalizer(2048))->normalize('  https://WWW.Example.com/A?utm_source=x  ');

        $this->assertSame('https://WWW.Example.com/A?utm_source=x', $url->original);
        $this->assertSame('example.com', $url->host);
        $this->assertSame(64, strlen($url->hash()));
    }

    #[DataProvider('rejections')]
    public function test_rejects(string $input, string $reason): void
    {
        try {
            $this->normalize($input);
            $this->fail("Expected rejection ($reason) for: $input");
        } catch (InvalidUrl $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public static function rejections(): array
    {
        return [
            'empty' => ['', InvalidUrl::EMPTY],
            'blank' => ['   ', InvalidUrl::EMPTY],
            'javascript' => ['javascript:alert(1)', InvalidUrl::UNSUPPORTED_SCHEME],
            'javascript mixed case' => ['JavaScript:alert(1)', InvalidUrl::UNSUPPORTED_SCHEME],
            'data' => ['data:text/html,<b>x</b>', InvalidUrl::UNSUPPORTED_SCHEME],
            'file' => ['file:///etc/passwd', InvalidUrl::UNSUPPORTED_SCHEME],
            'intent' => ['intent://scan/#Intent;scheme=zxing;end', InvalidUrl::UNSUPPORTED_SCHEME],
            'ftp' => ['ftp://example.com', InvalidUrl::UNSUPPORTED_SCHEME],
            'mailto' => ['mailto:a@example.com', InvalidUrl::UNSUPPORTED_SCHEME],
            'user and password' => ['https://user:pass@example.com', InvalidUrl::CREDENTIALS],
            'user only' => ['https://user@example.com', InvalidUrl::CREDENTIALS],
            'host confusion with @' => ['https://example.com@evil.com', InvalidUrl::CREDENTIALS],
            'backslash' => ['https://example.com\\@evil.com', InvalidUrl::INVALID_CHARACTERS],
            'inner space' => ['https://exa mple.com', InvalidUrl::INVALID_CHARACTERS],
            'control character' => ["https://example.com/\x07", InvalidUrl::INVALID_CHARACTERS],
            'invalid utf-8' => ["https://example.com/\xFF", InvalidUrl::INVALID_CHARACTERS],
            'localhost' => ['https://localhost/x', InvalidUrl::INVALID_HOST],
            'shorthand ipv4' => ['http://127.1', InvalidUrl::INVALID_HOST],
            'decimal ipv4' => ['http://2130706433', InvalidUrl::INVALID_HOST],
            'hex ipv4' => ['http://0x7f.0.0.1', InvalidUrl::INVALID_HOST],
            'zero-padded ipv4' => ['http://127.000.000.001', InvalidUrl::INVALID_HOST],
            'empty host' => ['https:///path', InvalidUrl::INVALID_HOST],
            'missing slashes' => ['http:example.com', InvalidUrl::INVALID_HOST],
            'label with leading hyphen' => ['https://-bad.example.com', InvalidUrl::INVALID_HOST],
            'unclosed ipv6' => ['https://[::1/x', InvalidUrl::INVALID_HOST],
            'invalid ipv6' => ['https://[zz::1]/', InvalidUrl::INVALID_HOST],
            'port zero' => ['https://example.com:0', InvalidUrl::INVALID_PORT],
            'port too large' => ['https://example.com:70000', InvalidUrl::INVALID_PORT],
        ];
    }

    public function test_length_limit_is_2048_characters(): void
    {
        $prefix = 'https://example.com/';
        $atLimit = $prefix.str_repeat('a', 2048 - strlen($prefix));

        $this->assertSame($atLimit, $this->normalize($atLimit));
        $this->assertRejectedAs($atLimit.'a', InvalidUrl::TOO_LONG);
    }

    public function test_length_limit_counts_characters_not_bytes(): void
    {
        $prefix = 'https://example.com/';
        $arabic = $prefix.str_repeat('م', 2048 - strlen($prefix));

        // Input is within 2048 characters, but percent-encoding makes the normalized form longer.
        $this->assertRejectedAs($arabic, InvalidUrl::TOO_LONG);
        $this->assertSame(
            $prefix.str_repeat('%D9%85', 10),
            $this->normalize($prefix.str_repeat('م', 10)),
        );
    }

    public function test_added_scheme_counts_towards_the_limit(): void
    {
        $withoutScheme = 'example.com/'.str_repeat('a', 2048 - strlen('example.com/'));

        $this->assertRejectedAs($withoutScheme, InvalidUrl::TOO_LONG);
    }

    private function assertRejectedAs(string $input, string $reason): void
    {
        try {
            $this->normalize($input);
            $this->fail("Expected rejection ($reason)");
        } catch (InvalidUrl $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
