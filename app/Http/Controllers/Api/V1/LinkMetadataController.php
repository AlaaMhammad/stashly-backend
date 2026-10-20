<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LinkResource;
use App\Services\Links\LinkService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinkMetadataController extends Controller
{
    /**
     * API-LINK-009: 202 {link} with metadata.status = pending. Limited to
     * 1/min per link and 5/min per user (throttle:metadata-refresh, DEC-003).
     */
    public function refresh(Request $request, string $link, LinkService $links): JsonResponse
    {
        $model = $links->refreshMetadata($links->findOwned($request->user(), $link));

        return ApiResponse::data(['link' => LinkResource::make($model)->resolve($request)], 202);
    }
}
