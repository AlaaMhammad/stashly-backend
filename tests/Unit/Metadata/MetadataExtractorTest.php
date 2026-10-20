<?php

namespace Tests\Unit\Metadata;

use App\Services\Metadata\ExtractedMetadata;
use App\Services\Metadata\FetchedPage;
use App\Services\Metadata\MetadataExtractor;
use PHPUnit\Framework\TestCase;

class MetadataExtractorTest extends TestCase
{
    private function extract(string $html, string $type = 'other', string $contentType = 'text/html; charset=utf-8', string $url = 'https://example.com/post/1')
    {
        return (new MetadataExtractor)->extract(new FetchedPage($url, $html, $contentType, false), $type);
    }

    public function test_reads_open_graph_metadata(): void
    {
        $meta = $this->extract('<html><head><title>Plain title</title>
            <meta property="og:title" content="OG title">
            <meta property="og:description" content="OG description">
            <meta property="og:site_name" content="Example News">
            <meta property="og:image" content="https://cdn.example.com/cover.jpg">
            <link rel="icon" href="https://example.com/icon.png"></head><body></body></html>');

        $this->assertSame('OG title', $meta->title);
        $this->assertSame('OG description', $meta->description);
        $this->assertSame('Example News', $meta->siteName);
        $this->assertSame('https://cdn.example.com/cover.jpg', $meta->previewImageUrl);
        $this->assertSame('https://example.com/icon.png', $meta->faviconUrl);
    }

    public function test_falls_back_to_title_tag_and_meta_description(): void
    {
        $meta = $this->extract('<title> Just   a
            title </title><meta name="description" content="Plain description"><meta name="twitter:image" content="/img/t.png">');

        $this->assertSame('Just a title', $meta->title);
        $this->assertSame('Plain description', $meta->description);
        $this->assertSame('https://example.com/img/t.png', $meta->previewImageUrl);
        $this->assertNull($meta->siteName);
        $this->assertNull($meta->faviconUrl);
    }

    public function test_page_without_metadata_yields_nulls(): void
    {
        $meta = $this->extract('<html><body><p>hello</p></body></html>', 'video');

        $this->assertNull($meta->title);
        $this->assertNull($meta->description);
        $this->assertNull($meta->previewImageUrl);
        $this->assertSame('video', $meta->contentType);
    }

    public function test_resolves_relative_urls_against_the_final_url(): void
    {
        $meta = $this->extract('<link rel="shortcut icon apple-touch-icon" href="../fav.ico"><meta property="og:image" content="//img.example.net/a.png">',
            url: 'https://example.com/a/b/page');

        $this->assertSame('https://example.com/a/fav.ico', $meta->faviconUrl);
        $this->assertSame('https://img.example.net/a.png', $meta->previewImageUrl);
    }

    public function test_prefers_plain_icon_over_apple_touch_icon(): void
    {
        $meta = $this->extract('<link rel="apple-touch-icon" href="/touch.png"><link rel="icon" href="/fav.png"><link rel="mask-icon" href="/mask.svg">');

        $this->assertSame('https://example.com/fav.png', $meta->faviconUrl);
    }

    public function test_rejects_dangerous_urls(): void
    {
        foreach (['javascript:alert(1)', 'data:image/png;base64,AAAA', 'file:///etc/passwd', 'ftp://example.com/x.png',
            'https://user:pass@example.com/x.png', 'https://example.com/a b.png'] as $bad) {
            $meta = $this->extract('<meta property="og:image" content="'.$bad.'"><link rel="icon" href="'.$bad.'">');

            $this->assertNull($meta->previewImageUrl, $bad);
            $this->assertNull($meta->faviconUrl, $bad);
        }

        $meta = $this->extract('<meta property="og:image" content="https://example.com/'.str_repeat('a', 2100).'">');
        $this->assertNull($meta->previewImageUrl, 'over 2048 characters');
    }

    public function test_text_is_sanitized_and_capped(): void
    {
        $meta = $this->extract('<meta property="og:title" content="'.str_repeat('t', 600).'">
            <meta property="og:description" content="line1&#10;line2'."\x07".' &lt;script&gt;alert(1)&lt;/script&gt;&#8232;end">
            <meta property="og:site_name" content="'.str_repeat('s', 300).'">');

        $this->assertSame(500, mb_strlen($meta->title));
        $this->assertSame(255, mb_strlen($meta->siteName));
        $this->assertSame('line1 line2 <script>alert(1)</script> end', $meta->description);
    }

    public function test_title_tag_markup_is_not_kept_as_markup(): void
    {
        $meta = $this->extract('<title>Hello <b>bold</b> &amp; &lt;i&gt;</title>');

        $this->assertStringNotContainsString('<b>', (string) $meta->title);
        // Text content only: the element is dropped, its text and escaped text stay as text.
        $this->assertSame('Hello bold & <i>', $meta->title);
    }

    public function test_decodes_windows_1256_arabic_pages(): void
    {
        $html = '<html><head><title>'.iconv('UTF-8', 'CP1256', 'مرحبا بالعالم').'</title></head></html>';

        $this->assertSame('مرحبا بالعالم', $this->extract($html, contentType: 'text/html; charset=windows-1256')->title);
    }

    public function test_charset_from_meta_tag_and_invalid_utf8_fallback(): void
    {
        $meta = $this->extract('<meta charset="iso-8859-1"><title>'.iconv('UTF-8', 'ISO-8859-1', 'Café').'</title>', contentType: 'text/html');
        $this->assertSame('Café', $meta->title);

        $broken = $this->extract("<title>Caf\xE9</title>", contentType: 'text/html');
        $this->assertSame('Café', $broken->title);
    }

    public function test_content_type_hint_only_upgrades_other(): void
    {
        $this->assertSame('video', $this->extract('<meta property="og:type" content="video.other">')->contentType);
        $this->assertSame('article', $this->extract('<meta property="og:type" content="article">')->contentType);
        $this->assertSame('product', $this->extract('<meta property="og:type" content="og:product">')->contentType);
        $this->assertSame('other', $this->extract('<meta property="og:type" content="website">')->contentType);
        // Host-based types win over page hints.
        $this->assertSame('social', $this->extract('<meta property="og:type" content="article">', 'social')->contentType);
    }

    public function test_hostile_or_broken_html_does_not_crash_or_read_files(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd"><!ENTITY remote SYSTEM "http://127.0.0.1/x">]>
            <html><head><title>&xxe;&remote;</title></head></html>';

        $meta = $this->extract($xxe);
        $this->assertStringNotContainsString('root:', (string) $meta->title);

        $this->assertNull($this->extract('')->title);
        $this->assertNull($this->extract("\x00\x01\x02<<<>>>")->title);
        // Very deep bodies must not hide head metadata, and must not crash without a head.
        $this->assertSame('ok', $this->extract('<html><head><title>ok</title></head><body>'.str_repeat('<div>', 5000).'</body></html>')->title);
        $this->assertInstanceOf(ExtractedMetadata::class, $this->extract(str_repeat('<div>', 5000)));
    }
}
