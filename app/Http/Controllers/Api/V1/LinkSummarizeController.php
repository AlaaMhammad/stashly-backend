<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LinkResource;
use App\Services\Ai\AiException;
use App\Services\Ai\LinkAiService;
use App\Services\Links\LinkService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinkSummarizeController extends Controller
{
    /**
     * API-AI-001 (DEC-038): synchronous Gemini summary and tag suggestions for one of the
     * user's links. 200 {link}; 404 for a link that is not theirs; 422 when the link has
     * nothing to summarize; 502/503/504 AI_INVALID_RESPONSE / AI_SERVICE_UNAVAILABLE / AI_TIMEOUT.
     */
    public function __invoke(Request $request, string $link, LinkService $links, LinkAiService $ai): JsonResponse
    {
        $model = $links->findOwned($request->user(), $link);

        try {
            $model = $ai->summarize($model);
        } catch (AiException $e) {
            throw $e->toApiException();
        }

        return ApiResponse::data(['link' => LinkResource::make($model)->resolve($request)]);
    }
}
