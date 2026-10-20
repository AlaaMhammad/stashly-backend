<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PreviewLinkRequest;
use App\Http\Resources\Api\V1\LinkResource;
use App\Services\Links\LinkService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class LinkPreviewController extends Controller
{
    /**
     * API-LINK-001: 200 {url, normalized_url, source_domain, content_type,
     * duplicate, existing_link, metadata}. 422 INVALID_URL.
     *
     * metadata is null until the SSRF-safe fetcher exists (Phase 6); the
     * contract's 4-second preview fetch applies from then.
     */
    public function __invoke(PreviewLinkRequest $request, LinkService $links): JsonResponse
    {
        $url = $links->normalize($request->validated('url'));
        $existing = $links->findDuplicate($request->user(), $url);

        return ApiResponse::data([
            'url' => $url->original,
            'normalized_url' => $url->normalized,
            'source_domain' => $url->host,
            'content_type' => $existing?->content_type ?? $links->classify($url),
            'duplicate' => $existing !== null,
            'existing_link' => $existing ? LinkResource::make($existing)->resolve($request) : null,
            'metadata' => null,
        ]);
    }
}
