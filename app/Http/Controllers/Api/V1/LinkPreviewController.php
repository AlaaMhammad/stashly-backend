<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PreviewLinkRequest;
use App\Http\Resources\Api\V1\LinkResource;
use App\Models\Link;
use App\Services\Links\LinkService;
use App\Services\Metadata\FetchFailure;
use App\Services\Metadata\MetadataLookup;
use App\Support\Api\ApiDate;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class LinkPreviewController extends Controller
{
    /**
     * API-LINK-001: 200 {url, normalized_url, source_domain, content_type,
     * duplicate, existing_link, metadata}. 422 INVALID_URL.
     *
     * metadata: for an already saved URL the saved link's metadata; otherwise
     * the result of a live lookup limited to 4 seconds (status "ready"), or
     * null when nothing was found in time (DEC-030). Saving never needs it.
     */
    public function __invoke(PreviewLinkRequest $request, LinkService $links, MetadataLookup $lookup): JsonResponse
    {
        $url = $links->normalize($request->validated('url'));
        $existing = $links->findDuplicate($request->user(), $url);
        $contentType = $existing?->content_type ?? $links->classify($url);
        $metadata = null;

        if ($existing !== null) {
            $existingLink = LinkResource::make($existing)->resolve($request);
            $metadata = $existingLink['metadata'];
        } else {
            try {
                $found = $lookup->lookup(
                    $url->fetchUrl,
                    (float) config('stashly.metadata.preview_timeout_seconds'),
                    $contentType,
                );
                $contentType = $found->contentType;
                $metadata = [
                    'status' => Link::STATUS_READY,
                    'title' => $found->title,
                    'description' => $found->description,
                    'site_name' => $found->siteName,
                    'favicon_url' => $found->faviconUrl,
                    'preview_image_url' => $found->previewImageUrl,
                    'fetched_at' => ApiDate::format(now()),
                    'error_code' => null,
                ];
            } catch (FetchFailure) {
                // Blocked, unreachable, slow or not HTML: the preview just has no metadata.
            }
        }

        return ApiResponse::data([
            'url' => $url->original,
            'normalized_url' => $url->normalized,
            'source_domain' => $url->host,
            'content_type' => $contentType,
            'duplicate' => $existing !== null,
            'existing_link' => $existingLink ?? null,
            'metadata' => $metadata,
        ]);
    }
}
