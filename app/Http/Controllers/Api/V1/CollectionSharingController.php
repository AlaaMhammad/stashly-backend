<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BrokenLinksRequest;
use App\Http\Requests\Api\V1\ShareCollectionRequest;
use App\Models\Collection;
use App\Models\Link;
use App\Services\Collections\CollectionService;
use App\Services\Collections\CollectionSharingService;
use App\Support\Api\ApiDate;
use App\Support\Api\ApiResponse;
use App\Support\Api\KeysetPaginator;
use Illuminate\Http\JsonResponse;

class CollectionSharingController extends Controller
{
    /**
     * API-COLL-007 (DEC-041): switch public read-only sharing on or off.
     * 200 {is_public, share_slug, share_url}; slug and url are null while private. 404 for a foreign collection.
     */
    public function share(ShareCollectionRequest $request, string $collection, CollectionService $collections, CollectionSharingService $sharing): JsonResponse
    {
        $model = $sharing->setPublic(
            $collections->findOwned($request->user(), $collection),
            $request->boolean('is_public'),
        );

        return ApiResponse::data([
            'is_public' => $model->is_public,
            'share_slug' => $model->is_public ? $model->share_slug : null,
            'share_url' => $model->is_public ? url('/api/v1/public/collections/'.$model->share_slug) : null,
        ]);
    }

    /**
     * API-PUB-001 (DEC-041), no authentication: the collection's name, description, owner display name and
     * links (title, url, description, saved date), newest first, with cursor pagination. Never exposed: notes,
     * AI data, tags, ids, e-mail or anything else about the account. An unknown, private or switched-off slug is a 404.
     */
    public function show(BrokenLinksRequest $request, string $slug): JsonResponse
    {
        $collection = Collection::query()
            ->where('share_slug', strtolower($slug))
            ->where('is_public', true)
            ->with('user:id,display_name')
            ->firstOrFail();

        $validated = $request->validated();
        [$items, $meta] = KeysetPaginator::paginate(
            // links.* only: the pivot table has its own created_at, which must not shadow the link's.
            $collection->links()->getQuery()->select('links.*'),
            ['created_at', 'id'],
            'desc',
            $validated['cursor'] ?? null,
            'public|'.$collection->id,
            (int) ($validated['limit'] ?? 20),
        );

        return (new JsonResponse([
            'data' => [
                'collection' => [
                    'name' => $collection->name,
                    'description' => $collection->description,
                    'owner' => ['display_name' => $collection->user->display_name],
                    'links_count' => $collection->links()->count(),
                    'updated_at' => ApiDate::format($collection->updated_at),
                ],
                'links' => $items->map(fn (Link $link) => [
                    'title' => $link->displayTitle(),
                    'url' => $link->normalized_url,
                    'description' => $link->metadata_description,
                    'created_at' => ApiDate::format($link->created_at),
                ])->values()->all(),
            ],
            'meta' => $meta,
        ]))->withHeaders(['Cache-Control' => 'public, max-age=60', 'X-Robots-Tag' => 'noindex']);
    }
}
