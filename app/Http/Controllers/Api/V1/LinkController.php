<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLinkRequest;
use App\Http\Resources\Api\V1\LinkResource;
use App\Services\Links\IdempotencyService;
use App\Services\Links\LinkService;
use App\Support\Api\ApiError;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class LinkController extends Controller
{
    /**
     * API-LINK-002: 201 {link} with metadata.status = pending.
     * 409 DUPLICATE_LINK with details.existing_link. Honors Idempotency-Key.
     */
    public function store(StoreLinkRequest $request, LinkService $links, IdempotencyService $idempotency): JsonResponse
    {
        $user = $request->user();

        return $idempotency->run($user, $request->header('Idempotency-Key'), $request->all(), function () use ($request, $links, $user) {
            $result = $links->save($user, $request->validated());
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
}
