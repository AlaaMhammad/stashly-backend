<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListCollectionsRequest;
use App\Http\Requests\Api\V1\StoreCollectionRequest;
use App\Http\Requests\Api\V1\UpdateCollectionLinksRequest;
use App\Http\Requests\Api\V1\UpdateCollectionRequest;
use App\Models\Collection;
use App\Services\Collections\CollectionPresenter;
use App\Services\Collections\CollectionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\KeysetPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CollectionController extends Controller
{
    public function __construct(
        private readonly CollectionService $collections,
        private readonly CollectionPresenter $presenter,
    ) {}

    /**
     * API-COLL-001: 200 {data:[collection], meta}. Default sort "recent"
     * (newest first); "name" is alphabetical ignoring case.
     */
    public function index(ListCollectionsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $sort = $validated['sort'] ?? ListCollectionsRequest::SORT_RECENT;
        $byName = $sort === ListCollectionsRequest::SORT_NAME;

        [$items, $meta] = KeysetPaginator::paginate(
            Collection::query()->whereBelongsTo($user),
            $byName ? ['name_key', 'id'] : ['created_at', 'id'],
            $byName ? 'asc' : 'desc',
            $validated['cursor'] ?? null,
            $user->getKey().'|collections|'.$sort,
            (int) ($validated['limit'] ?? 50),
        );

        return ApiResponse::list($this->presenter->many($items, $request), $meta);
    }

    /**
     * API-COLL-002: 201 {collection}. 422 duplicate name; 422 LIMIT_EXCEEDED at 100.
     */
    public function store(StoreCollectionRequest $request): JsonResponse
    {
        $collection = $this->collections->create($request->user(), $request->validated());

        return ApiResponse::data(['collection' => $this->presenter->one($collection, $request)], 201);
    }

    /**
     * API-COLL-003: 200 {collection}. 404.
     */
    public function show(Request $request, string $collection): JsonResponse
    {
        $model = $this->collections->findOwned($request->user(), $collection);

        return ApiResponse::data(['collection' => $this->presenter->one($model, $request)]);
    }

    /**
     * API-COLL-004: 200 {collection}.
     */
    public function update(UpdateCollectionRequest $request, string $collection): JsonResponse
    {
        $model = $this->collections->update(
            $this->collections->findOwned($request->user(), $collection),
            $request->validated(),
        );

        return ApiResponse::data(['collection' => $this->presenter->one($model, $request)]);
    }

    /**
     * API-COLL-005: 204. Links are kept; those in no other collection become Unsorted.
     */
    public function destroy(Request $request, string $collection): Response
    {
        $this->collections->findOwned($request->user(), $collection)->delete();

        return response()->noContent();
    }

    /**
     * API-COLL-006: atomic attach/detach. 200 {collection}.
     */
    public function updateLinks(UpdateCollectionLinksRequest $request, string $collection): JsonResponse
    {
        $model = $this->collections->updateLinks(
            $this->collections->findOwned($request->user(), $collection),
            $request->validated('attach') ?? [],
            $request->validated('detach') ?? [],
        );

        return ApiResponse::data(['collection' => $this->presenter->one($model, $request)]);
    }
}
