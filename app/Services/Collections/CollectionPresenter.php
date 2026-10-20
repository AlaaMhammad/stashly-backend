<?php

namespace App\Services\Collections;

use App\Http\Resources\Api\V1\CollectionResource;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Builds shared "collection" objects with links_count and up to four
 * cover_images, using one count query and one cover query for any number
 * of collections.
 */
class CollectionPresenter
{
    private const COVER_COUNT = 4;

    /**
     * @param  EloquentCollection<int, Collection>  $collections
     * @return list<array<string, mixed>>
     */
    public function many(EloquentCollection $collections, Request $request): array
    {
        if ($collections->isEmpty()) {
            return [];
        }

        $collections->loadCount('links');
        $covers = $this->covers($collections->modelKeys());

        return $collections
            ->map(fn (Collection $c) => CollectionResource::make($c)->withCovers($covers[$c->getKey()] ?? [])->resolve($request))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function one(Collection $collection, Request $request): array
    {
        return $this->many(new EloquentCollection([$collection]), $request)[0];
    }

    /**
     * Preview images of each collection's newest links that have one (DEC-012).
     *
     * @param  list<string>  $collectionIds
     * @return array<string, list<string>>
     */
    private function covers(array $collectionIds): array
    {
        $ranked = DB::table('collection_link')
            ->join('links', 'links.id', '=', 'collection_link.link_id')
            ->whereIn('collection_link.collection_id', $collectionIds)
            ->whereNotNull('links.metadata_preview_image_url')
            ->select('collection_link.collection_id', 'links.metadata_preview_image_url as url')
            ->selectRaw('row_number() over (partition by collection_link.collection_id order by links.created_at desc, links.id desc) as position');

        $covers = [];
        DB::query()->fromSub($ranked, 'ranked')
            ->where('position', '<=', self::COVER_COUNT)
            ->orderBy('collection_id')
            ->orderBy('position')
            ->get()
            ->each(function ($row) use (&$covers) {
                $covers[$row->collection_id][] = $row->url;
            });

        return $covers;
    }
}
