<?php

namespace App\Services\Metadata;

/**
 * Fetch a page safely and extract its metadata. Used by the queue job
 * (8 s budget) and by POST /links/preview (4 s budget).
 */
class MetadataLookup
{
    public function __construct(
        private readonly SafeFetcher $fetcher,
        private readonly MetadataExtractor $extractor,
    ) {}

    /**
     * @throws FetchFailure
     */
    public function lookup(string $url, float $timeoutSeconds, string $currentType): ExtractedMetadata
    {
        return $this->extractor->extract($this->fetcher->fetch($url, $timeoutSeconds), $currentType);
    }
}
