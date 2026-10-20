<?php

namespace App\Services\Metadata;

/**
 * One HTTP response as seen by the metadata fetcher. The body is only kept
 * for 2xx HTML responses and is cut at the size limit (truncated = true).
 */
final class TransportResponse
{
    /**
     * @param  array<string, string>  $headers  lowercase name => last value
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body = '',
        public readonly bool $truncated = false,
    ) {}

    public function contentType(): string
    {
        return $this->headers['content-type'] ?? '';
    }

    public function isHtml(): bool
    {
        return self::isHtmlType($this->contentType());
    }

    public static function isHtmlType(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType)[0]));

        return in_array($mediaType, ['text/html', 'application/xhtml+xml'], true);
    }
}
