<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AskAiRequest;
use App\Services\Ai\AiException;
use App\Services\Ai\LinkAiService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class AskAiController extends Controller
{
    /**
     * API-AI-002 (DEC-038): "Ask your links". 200 {query, answer, referenced_links[{id,title,url}]}.
     */
    public function __invoke(AskAiRequest $request, LinkAiService $ai): JsonResponse
    {
        $query = $request->validated('query');

        try {
            $result = $ai->ask($request->user(), $query);
        } catch (AiException $e) {
            throw $e->toApiException();
        }

        return ApiResponse::data(['query' => $query] + $result);
    }
}
