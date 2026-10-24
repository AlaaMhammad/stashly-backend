<?php

namespace App\Services\Links;

use App\Services\Tags\TagService;
use App\Support\Search\SearchText;
use Carbon\Carbon;

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
        // GET /links/search and GET /links/broken (DEC-041)
        public readonly ?bool $hasAiSummary = null,
        public readonly ?Carbon $dateFrom = null,
        public readonly ?Carbon $dateTo = null,
        public readonly bool $archivedOnly = false,
        public readonly ?bool $broken = null,
        public readonly string $sortField = 'created_at',
        public readonly ?string $direction = null,
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
     * GET /links/search: compound filters (all combine with AND) and an explicit sort field and direction.
     * There is no archive concept, so is_archived=true matches nothing and false matches everything.
     *
     * @param  array<string, mixed>  $input  validated query parameters
     */
    public static function fromSearchInput(array $input): self
    {
        $flag = fn (string $key) => isset($input[$key]) ? in_array($input[$key], ['true', '1'], true) : null;
        $tag = TagService::normalize((string) ($input['tag'] ?? ''));
        $field = $input['sort'] ?? 'created_at';

        return new self(
            words: SearchText::words((string) ($input['query'] ?? '')),
            favorite: $flag('is_favorite'),
            collectionIds: self::list($input['collection_id'] ?? null),
            tags: $tag === '' ? [] : [$tag],
            hasAiSummary: $flag('has_ai_summary'),
            dateFrom: self::date($input['date_from'] ?? null, false),
            dateTo: self::date($input['date_to'] ?? null, true),
            archivedOnly: $flag('is_archived') === true,
            sortField: $field,
            direction: $input['direction'] ?? ($field === 'title' ? 'asc' : 'desc'),
        );
    }

    /**
     * A date (Y-m-d) means the whole day; a date-time is taken as given. Always UTC.
     */
    public static function date(?string $value, bool $endOfDay): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = Carbon::parse($value, 'UTC')->utc();

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? ($endOfDay ? $date->endOfDay() : $date->startOfDay()) : $date;
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
            $this->hasAiSummary, $this->dateFrom?->toIso8601String(), $this->dateTo?->toIso8601String(),
            $this->archivedOnly, $this->broken, $this->sortField, $this->direction,
        ]));
    }
}
