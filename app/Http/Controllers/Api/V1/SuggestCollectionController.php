<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuggestCollectionRequest;
use App\Services\Ai\AiException;
use App\Services\Ai\LinkInsightService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class SuggestCollectionController extends Controller
{
    /**
     * API-AI-006 (DEC-039): which of the user's collections fits a link that is about to be saved.
     * 200 {suggested_collection_id|null, suggested_collection_name|null, confidence, reason}.
     */
    public function __invoke(SuggestCollectionRequest $request, LinkInsightService $ai): JsonResponse
    {
        try {
            $suggestion = $ai->suggestCollection(
                $request->user(),
                $request->validated('title'),
                $request->validated('url'),
                $request->validated('description'),
            );
        } catch (AiException $e) {
            throw $e->toApiException();
        }

        return ApiResponse::data($suggestion);
    }
}
