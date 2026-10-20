<?php

namespace App\Services\Metadata;

final class ExtractedMetadata
{
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $siteName,
        public readonly ?string $faviconUrl,
        public readonly ?string $previewImageUrl,
        /** content_type after refinement; equals the input type when nothing better is known. */
        public readonly string $contentType,
    ) {}
}
