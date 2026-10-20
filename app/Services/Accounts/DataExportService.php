<?php

namespace App\Services\Accounts;

use App\Http\Resources\Api\V1\LinkResource;
use App\Models\Collection;
use App\Models\Link;
use App\Models\User;
use App\Support\Api\ApiDate;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * API-PROF-004: all of the user's data as one JSON document, streamed in
 * chunks so large libraries do not exhaust memory. Every query is scoped to
 * the user. Never exported: password hash, tokens, reset tokens, sessions,
 * idempotency records and internal columns (DEC-013, schema version 1).
 *
 * {
 *   "schema_version": 1, "exported_at", "user": {...},
 *   "collections": [{id, name, description, created_at, updated_at, link_ids[]}],
 *   "tags": ["name", ...],
 *   "links": [ <the API "link" object> ]
 * }
 */
class DataExportService
{
    public const SCHEMA_VERSION = 1;

    private const CHUNK = 500;

    /**
     * @return Generator<int, string> JSON fragments; concatenated they form the document
     */
    public function stream(User $user, Request $request): Generator
    {
        yield '{"schema_version":'.self::SCHEMA_VERSION
            .',"exported_at":'.$this->json(ApiDate::format(now()))
            .',"user":'.$this->json([
                'id' => $user->id,
                'display_name' => $user->display_name,
                'email' => $user->email,
                'created_at' => ApiDate::format($user->created_at),
                'terms_version' => $user->terms_version,
                'terms_accepted_at' => $user->terms_accepted_at ? ApiDate::format($user->terms_accepted_at) : null,
            ]);

        yield ',"collections":[';
        $first = true;
        foreach ($this->chunks($user->collections()->with('links:id')) as $collection) {
            yield ($first ? '' : ',').$this->json($this->collection($collection));
            $first = false;
        }

        yield '],"tags":'.$this->json($user->tags()->orderBy('name')->pluck('name')->all());

        yield ',"links":[';
        $first = true;
        foreach ($this->chunks($user->links()->with(['collections', 'tags'])) as $link) {
            yield ($first ? '' : ',').$this->json(LinkResource::make($link)->resolve($request));
            $first = false;
        }

        yield ']}';
    }

    /**
     * Rows in id order, one bounded chunk at a time (keyset, no OFFSET).
     *
     * @param  Builder<Collection|Link>  $query
     * @return Generator<int, Collection|Link>
     */
    private function chunks(Builder|Relation $query): Generator
    {
        $lastId = null;

        do {
            $rows = (clone $query)
                ->when($lastId !== null, fn ($q) => $q->where($q->getModel()->qualifyColumn('id'), '>', $lastId))
                ->orderBy($query->getModel()->qualifyColumn('id'))
                ->limit(self::CHUNK)
                ->get();

            yield from $rows;

            $lastId = $rows->last()?->getKey();
        } while ($rows->count() === self::CHUNK);
    }

    /**
     * @return array<string, mixed>
     */
    private function collection(Collection $collection): array
    {
        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'description' => $collection->description,
            'created_at' => ApiDate::format($collection->created_at),
            'updated_at' => ApiDate::format($collection->updated_at),
            'link_ids' => $collection->links->map(fn (Link $link) => $link->id)->sort()->values()->all(),
        ];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
