<?php

namespace App\Services\Metadata;

use App\Services\Links\ContentTypeClassifier;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

/**
 * Reads title, description, site name, favicon, preview image URL and a
 * content-type hint from HTML. Everything extracted is untrusted text: tags
 * are never kept, control characters are removed, lengths are capped, and
 * URLs must be plain http(s) without credentials. Images are never fetched.
 */
class MetadataExtractor
{
    private const TITLE_MAX = 500;

    private const DESCRIPTION_MAX = 1000;

    private const SITE_NAME_MAX = 255;

    private const URL_MAX = 2048;

    public function extract(FetchedPage $page, string $currentType): ExtractedMetadata
    {
        $html = $this->headOnly($this->withoutCharsetDeclarations($this->toUtf8($page->body, $page->contentType)));

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            // LIBXML_NONET: never touch the network (DTDs, entities).
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($dom);
            $meta = $this->metaTags($xpath);

            return new ExtractedMetadata(
                title: $this->text($meta['og:title'] ?? $meta['twitter:title'] ?? $this->titleTag($xpath), self::TITLE_MAX),
                description: $this->text($meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? null, self::DESCRIPTION_MAX),
                siteName: $this->text($meta['og:site_name'] ?? $meta['application-name'] ?? null, self::SITE_NAME_MAX),
                faviconUrl: $this->url($this->iconHref($xpath), $page->url),
                previewImageUrl: $this->url($meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? null, $page->url),
                contentType: $this->refineType($currentType, $meta['og:type'] ?? ''),
            );
        } catch (Throwable) {
            // Unparseable page: the lookup succeeded but yielded nothing usable.
            return new ExtractedMetadata(null, null, null, null, null, $currentType);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Host-based types (video, social, product) are more specific than page
     * hints, so a page hint only upgrades "other" (DEC-007).
     */
    private function refineType(string $current, string $ogType): string
    {
        if ($current !== ContentTypeClassifier::OTHER) {
            return $current;
        }

        $ogType = strtolower(trim($ogType));

        return match (true) {
            str_starts_with($ogType, 'video') => ContentTypeClassifier::VIDEO,
            $ogType === 'article' || str_starts_with($ogType, 'article.') => ContentTypeClassifier::ARTICLE,
            str_contains($ogType, 'product') => ContentTypeClassifier::PRODUCT,
            default => $current,
        };
    }

    /**
     * @return array<string, string> lowercase property/name => first non-empty content
     */
    private function metaTags(DOMXPath $xpath): array
    {
        $meta = [];
        foreach ($xpath->query('//meta[@content]') as $node) {
            $key = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name')));
            $content = $node->getAttribute('content');

            if ($key !== '' && trim($content) !== '' && ! isset($meta[$key])) {
                $meta[$key] = $content;
            }
        }

        return $meta;
    }

    private function titleTag(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//title')->item(0);

        return $node?->textContent;
    }

    private function iconHref(DOMXPath $xpath): ?string
    {
        $preferred = null;
        $touch = null;

        foreach ($xpath->query('//link[@rel][@href]') as $node) {
            $tokens = preg_split('/\s+/', strtolower(trim($node->getAttribute('rel'))));
            if (in_array('icon', $tokens, true)) {
                $preferred ??= $node->getAttribute('href');
            } elseif (in_array('apple-touch-icon', $tokens, true)) {
                $touch ??= $node->getAttribute('href');
            }
        }

        return $preferred ?? $touch;
    }

    private function text(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function url(?string $value, string $base): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/[\s\x00-\x1F\x7F]/', $value)) {
            return null;
        }

        try {
            $resolved = UriResolver::resolve(new Uri($base), new Uri($value));
        } catch (Throwable) {
            return null;
        }

        if (! in_array($resolved->getScheme(), ['http', 'https'], true)
            || $resolved->getHost() === ''
            || $resolved->getUserInfo() !== '') {
            return null;
        }

        $url = (string) $resolved->withFragment('');

        return strlen($url) <= self::URL_MAX ? $url : null;
    }

    /**
     * The text is already UTF-8; a leftover <meta charset> would make libxml
     * decode it a second time.
     */
    private function withoutCharsetDeclarations(string $html): string
    {
        // \x22 and \x27 are the double and single quote characters.
        return preg_replace('/<meta\s[^>]*(?:charset\s*=|http-equiv\s*=\s*[\x22\x27]?content-type)[^>]*>/i', '', $html) ?? $html;
    }

    /**
     * Metadata lives in <head>. Parsing only that part is cheaper and keeps
     * pathological bodies (e.g. thousands of nested tags, which make libxml
     * give up on the whole document) from hiding the metadata.
     */
    private function headOnly(string $html): string
    {
        if (preg_match('/<\/head\s*>|<body[\s>]/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return substr($html, 0, $m[0][1]);
        }

        return $html;
    }

    /**
     * Decode to UTF-8 using the HTTP charset, then a <meta charset>, then
     * UTF-8 if valid, else Windows-1252 (the web's default legacy charset).
     */
    private function toUtf8(string $body, string $contentType): string
    {
        $charset = null;
        if (preg_match('/charset\s*=\s*["\']?([\w\-:.]+)/i', $contentType, $m)) {
            $charset = $m[1];
        } elseif (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([\w\-:.]+)/i', substr($body, 0, 4096), $m)) {
            $charset = $m[1];
        }

        $charset = strtolower($charset ?? '');
        if ($charset === '' || $charset === 'utf-8' || $charset === 'utf8') {
            if (mb_check_encoding($body, 'UTF-8')) {
                return $body;
            }
            $charset = $charset === '' ? 'windows-1252' : 'utf-8';
        }

        $converted = @iconv($charset, 'UTF-8//IGNORE', $body);

        return $converted !== false ? $converted : mb_convert_encoding($body, 'UTF-8', 'UTF-8');
    }
}
