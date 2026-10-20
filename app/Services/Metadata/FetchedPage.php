<?php

namespace App\Services\Metadata;

final class FetchedPage
{
    public function __construct(
        /** The URL that finally answered (after redirects); base for relative links. */
        public readonly string $url,
        public readonly string $body,
        public readonly string $contentType,
        public readonly bool $truncated,
    ) {}
}
