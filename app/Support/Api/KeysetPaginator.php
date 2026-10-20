<?php

namespace App\Support\Api;

use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Cursor pagination over a unique ordering (e.g. created_at, id), so pages
 * never skip or repeat rows even when earlier columns tie. Cursors are signed
 * and bound to a caller-supplied string (user + filters + sort).
 */
final class KeysetPaginator
{
    /**
     * @param  Builder<Model>  $query  filtered, unordered query
     * @param  list<string>  $columns  ordering columns; the last one must be unique
     * @param  'asc'|'desc'  $direction
     * @return array{0: Collection<int, Model>, 1: array<string, mixed>} items and list meta
     *
     * @throws ApiException INVALID_CURSOR
     */
    public static function paginate(
        Builder $query,
        array $columns,
        string $direction,
        ?string $cursor,
        string $binding,
        int $limit,
    ): array {
        // Contract: total is reported on the first page only.
        $total = $cursor === null ? (clone $query)->count() : null;
        $model = $query->getModel();

        if ($cursor !== null) {
            $values = Cursor::decode($cursor, $binding)['k'] ?? null;

            if (! is_array($values) || ! array_is_list($values) || count($values) !== count($columns)
                || array_filter($values, fn ($value) => ! is_scalar($value)) !== []) {
                throw ApiException::invalidCursor();
            }

            $query->where(fn (Builder $q) => self::after($q, array_map($model->qualifyColumn(...), $columns), $values, $direction === 'desc' ? '<' : '>'));
        }

        foreach ($columns as $column) {
            $query->orderBy($model->qualifyColumn($column), $direction);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->values();
        $last = $items->last();

        $meta = [
            'per_page' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore
                ? Cursor::encode(['k' => array_map(fn ($column) => $last->getRawOriginal($column), $columns)], $binding)
                : null,
        ];
        if ($total !== null) {
            $meta['total'] = $total;
        }

        return [$items, $meta];
    }

    /**
     * Rows strictly after the cursor: (c1 op v1) OR (c1 = v1 AND (c2 op v2 OR ...)).
     *
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns
     * @param  list<scalar>  $values
     */
    private static function after(Builder $query, array $columns, array $values, string $operator): void
    {
        $query->where($columns[0], $operator, $values[0]);

        if (count($columns) > 1) {
            $query->orWhere(fn (Builder $q) => $q
                ->where($columns[0], $values[0])
                ->where(fn (Builder $q) => self::after($q, array_slice($columns, 1), array_slice($values, 1), $operator)));
        }
    }
}
