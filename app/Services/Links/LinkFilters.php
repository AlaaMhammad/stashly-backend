<?php

namespace App\Services\Links;

use App\Services\Tags\TagService;
use App\Support\Search\SearchText;

/**
 * Validated GET /links filters in canonical form (contract 4.3).
 * Several tags must all match; values within any other filter combine with
 * OR; different filters combine with AND.
 */
final class LinkFilters
{
    public const SORT_NEWEST = 'newest';

    public const SORT_OLDEST = 'oldest';

    /**
     * @param  list<string>  $words  every word must match
     * @param  list<string>  $contentTypes
     * @param  list<string>  $sources
     * @param  list<string>  $collectionIds
     * @param  list<string>  $tags  normalized tag names
     */
    public function __construct(
        public readonly array $words = [],
        public readonly ?bool $favorite = null,
        public readonly array $contentTypes = [],
        public readonly array $sources = [],
        public readonly array $collectionIds = [],
        public readonly array $tags = [],
        public readonly ?int $savedWithinDays = null,
        public readonly string $sort = self::SORT_NEWEST,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated query parameters
     */
    public static function fromInput(array $input): self
    {
        $favorite = $input['favorite'] ?? null;
        $tags = array_values(array_unique(array_filter(array_map(
            TagService::normalize(...),
            explode(',', (string) ($input['tags'] ?? '')),
        ), 'strlen')));
        sort($tags);

        return new self(
            words: SearchText::words((string) ($input['q'] ?? '')),
            favorite: $favorite === null ? null : in_array($favorite, ['true', '1'], true),
            contentTypes: self::list($input['content_type'] ?? null),
            sources: self::list($input['source'] ?? null),
            collectionIds: self::list($input['collection_id'] ?? null),
            tags: $tags,
            savedWithinDays: isset($input['saved_within_days']) ? (int) $input['saved_within_days'] : null,
            sort: $input['sort'] ?? self::SORT_NEWEST,
        );
    }

    /**
     * Comma list to sorted, de-duplicated, lowercased values.
     *
     * @return list<string>
     */
    public static function list(?string $value): array
    {
        $items = array_filter(array_map(fn ($item) => strtolower(trim($item)), explode(',', (string) $value)), 'strlen');
        $items = array_values(array_unique($items));
        sort($items);

        return $items;
    }

    /**
     * Stable identity of the filter set; cursors are only valid for the same one.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->words, $this->favorite, $this->contentTypes, $this->sources,
            $this->collectionIds, $this->tags, $this->savedWithinDays, $this->sort,
        ]));
    }
}
