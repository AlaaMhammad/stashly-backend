<?php

namespace App\Services\Links;

use App\Exceptions\ApiException;
use App\Models\Link;
use App\Models\User;
use App\Support\Api\Cursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

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
        $binding = $user->getKey().'|'.$filters->fingerprint();
        $newestFirst = $filters->sort === LinkFilters::SORT_NEWEST;
        $direction = $newestFirst ? 'desc' : 'asc';

        $query = $this->filtered($user, $filters);

        if ($cursor !== null) {
            [$createdAt, $id] = $this->position($cursor, $binding);
            $operator = $newestFirst ? '<' : '>';

            $query->where(fn (Builder $q) => $q
                ->where('created_at', $operator, $createdAt)
                ->orWhere(fn (Builder $q) => $q->where('created_at', $createdAt)->where('id', $operator, $id)));
        }

        $rows = $query->orderBy('created_at', $direction)->orderBy('id', $direction)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->values();
        $last = $items->last();

        $meta = [
            'per_page' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore
                ? Cursor::encode(['t' => $last->getRawOriginal('created_at'), 'i' => $last->id], $binding)
                : null,
        ];

        // Contract: total is reported on the first page only.
        if ($cursor === null) {
            $meta['total'] = $this->filtered($user, $filters)->count();
        }

        return [$items, $meta];
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
        if ($filters->savedWithinDays !== null) {
            $query->where('created_at', '>=', now()->subDays($filters->savedWithinDays));
        }

        return $query;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function position(string $cursor, string $binding): array
    {
        $payload = Cursor::decode($cursor, $binding);
        $createdAt = $payload['t'] ?? null;
        $id = $payload['i'] ?? null;

        if (! is_string($createdAt) || ! is_string($id) || ! Str::isUlid($id)
            || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $createdAt)) {
            throw ApiException::invalidCursor();
        }

        return [$createdAt, $id];
    }
}
