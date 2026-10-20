<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReplaceLinkCollectionsRequest;
use App\Http\Requests\Api\V1\ReplaceLinkTagsRequest;
use App\Http\Resources\Api\V1\LinkResource;
use App\Services\Links\LinkService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class LinkRelationsController extends Controller
{
    public function __construct(private readonly LinkService $links) {}

    /**
     * API-LINK-007: 200 {link}. 422 if a collection is not the user's.
     */
    public function collections(ReplaceLinkCollectionsRequest $request, string $link): JsonResponse
    {
        $model = $this->links->replaceCollections(
            $this->links->findOwned($request->user(), $link),
            $request->validated('collection_ids'),
        );

        return ApiResponse::data(['link' => LinkResource::make($model)->resolve($request)]);
    }

    /**
     * API-LINK-008: 200 {link}.
     */
    public function tags(ReplaceLinkTagsRequest $request, string $link): JsonResponse
    {
        $model = $this->links->replaceTags(
            $this->links->findOwned($request->user(), $link),
            $request->validated('tags'),
        );

        return ApiResponse::data(['link' => LinkResource::make($model)->resolve($request)]);
    }
}
