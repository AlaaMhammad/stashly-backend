<?php

namespace App\Services\Links;

use App\Exceptions\ApiException;
use App\Models\Link;
use App\Models\User;
use App\Support\Api\KeysetPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * API-LINK-003: the user's links, filtered and keyset-paginated by
 * (created_at, id) so pages never skip or repeat items.
 */
class LinkSearch
{
    /**
     * @return array{0: Collection<int, Link>, 1: array<string, mixed>} items and list meta
     *
     * @throws ApiException INVALID_CURSOR
     */
    public function paginate(User $user, LinkFilters $filters, ?string $cursor, int $limit): array
    {
        return KeysetPaginator::paginate(
            $this->filtered($user, $filters)->with(['collections', 'tags']),
            [$filters->sortField === 'title' ? 'sort_title' : 'created_at', 'id'],
            $filters->direction ?? ($filters->sort === LinkFilters::SORT_NEWEST ? 'desc' : 'asc'),
            $cursor,
            $user->getKey().'|'.$filters->fingerprint(),
            $limit,
        );
    }

    /**
     * @return Builder<Link>
     */
    private function filtered(User $user, LinkFilters $filters): Builder
    {
        $query = Link::query()->whereBelongsTo($user);

        foreach ($filters->words as $word) {
            // "!" as the LIKE escape character works the same on MySQL and SQLite.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word);
            $query->whereRaw("search_text LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }
        if ($filters->favorite !== null) {
            $query->where('is_favorite', $filters->favorite);
        }
        if ($filters->contentTypes !== []) {
            $query->whereIn('content_type', $filters->contentTypes);
        }
        if ($filters->sources !== []) {
            $query->whereIn('source_domain', $filters->sources);
        }
        if ($filters->collectionIds !== []) {
            // In any of the given collections (OR). Foreign ids simply match nothing.
            $query->whereHas('collections', fn (Builder $q) => $q->whereIn('collections.id', $filters->collectionIds));
        }
        foreach ($filters->tags as $tag) {
            // Several tags: the link must have all of them (AND).
            $query->whereHas('tags', fn (Builder $q) => $q->where('tags.name', $tag));
        }
        if ($filters->hasAiSummary !== null) {
            $filters->hasAiSummary ? $query->whereNotNull('ai_summary') : $query->whereNull('ai_summary');
        }
        if ($filters->dateFrom !== null) {
            $query->where('created_at', '>=', $filters->dateFrom);
        }
        if ($filters->dateTo !== null) {
            $query->where('created_at', '<=', $filters->dateTo);
        }
        if ($filters->archivedOnly) {
            // Nothing can be archived yet (no archive feature), so there is nothing to find.
            $query->whereRaw('1 = 0');
        }
        if ($filters->broken !== null) {
            $query->where('is_broken', $filters->broken);
        }
        if ($filters->savedWithinDays !== null) {
            $query->where('created_at', '>=', now()->subDays($filters->savedWithinDays));
        }

        return $query;
    }
}
