<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListLinksRequest;
use App\Http\Requests\Api\V1\StoreLinkRequest;
use App\Http\Requests\Api\V1\UpdateLinkRequest;
use App\Http\Resources\Api\V1\LinkResource;
use App\Services\Links\IdempotencyService;
use App\Services\Links\LinkFilters;
use App\Services\Links\LinkSearch;
use App\Services\Links\LinkService;
use App\Support\Api\ApiError;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LinkController extends Controller
{
    public function __construct(private readonly LinkService $links) {}

    /**
     * API-LINK-003: 200 {data:[link], meta:{per_page, has_more, next_cursor, total}}.
     * 422 INVALID_CURSOR.
     */
    public function index(ListLinksRequest $request, LinkSearch $search): JsonResponse
    {
        $validated = $request->validated();

        [$items, $meta] = $search->paginate(
            $request->user(),
            LinkFilters::fromInput($validated),
            $validated['cursor'] ?? null,
            (int) ($validated['limit'] ?? 20),
        );

        return ApiResponse::list(LinkResource::collection($items)->resolve($request), $meta);
    }

    /**
     * API-LINK-002: 201 {link} with metadata.status = pending.
     * 409 DUPLICATE_LINK with details.existing_link. Honors Idempotency-Key.
     */
    public function store(StoreLinkRequest $request, IdempotencyService $idempotency): JsonResponse
    {
        $user = $request->user();

        return $idempotency->run($user, $request->header('Idempotency-Key'), $request->all(), function () use ($request, $user) {
            $result = $this->links->save($user, $request->validated());
            $link = LinkResource::make($result->link)->resolve($request);

            if (! $result->created) {
                return ApiError::response(
                    ApiError::DUPLICATE_LINK,
                    'This link is already saved.',
                    409,
                    ['existing_link' => $link],
                );
            }

            return ApiResponse::data(['link' => $link], 201);
        });
    }

    /**
     * API-LINK-004: 200 {link}. 404 for unknown or another user's link.
     */
    public function show(Request $request, string $link): JsonResponse
    {
        $model = $this->links->findOwned($request->user(), $link);

        return ApiResponse::data(['link' => LinkResource::make($model)->resolve($request)]);
    }

    /**
     * API-LINK-005: edit title, note or favorite only. 200 {link}.
     */
    public function update(UpdateLinkRequest $request, string $link): JsonResponse
    {
        $model = $this->links->findOwned($request->user(), $link);
        $model->fill($request->validated())->save();

        return ApiResponse::data(['link' => LinkResource::make($model)->resolve($request)]);
    }

    /**
     * API-LINK-006: permanent delete. 204.
     */
    public function destroy(Request $request, string $link): Response
    {
        $this->links->delete($this->links->findOwned($request->user(), $link));

        return response()->noContent();
    }
}
