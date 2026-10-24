<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AiRefreshRequest;
use App\Services\Ai\AiException;
use App\Services\Ai\LinkInsightService;
use App\Services\Links\LinkService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DEC-039: on-demand AI reading aids for one of the user's links. Every method answers 404 for a
 * link that is not theirs, and maps provider problems to AI_SERVICE_UNAVAILABLE (503),
 * AI_TIMEOUT (504) and AI_INVALID_RESPONSE (502).
 */
class LinkInsightsController extends Controller
{
    /**
     * API-AI-003: 200 {link_id, insights}. Cached in links.ai_insights; ?refresh=true recomputes.
     */
    public function keyPoints(AiRefreshRequest $request, string $link, LinkService $links, LinkInsightService $ai): JsonResponse
    {
        $model = $links->findOwned($request->user(), $link);

        try {
            $insights = $ai->keyPoints($model, $request->wantsRefresh());
        } catch (AiException $e) {
            throw $e->toApiException();
        }

        return ApiResponse::data(['link_id' => $model->id, 'insights' => $insights]);
    }

    /**
     * API-AI-004: 200 {link_id, action_plan}. Cached in links.ai_action_plan; ?refresh=true recomputes.
     */
    public function actionPlan(AiRefreshRequest $request, string $link, LinkService $links, LinkInsightService $ai): JsonResponse
    {
        $model = $links->findOwned($request->user(), $link);

        try {
            $plan = $ai->actionPlan($model, $request->wantsRefresh());
        } catch (AiException $e) {
            throw $e->toApiException();
        }

        return ApiResponse::data(['link_id' => $model->id, 'action_plan' => $plan]);
    }

    /**
     * API-AI-005: 200 {source_link_id, related_links[{id, title, url, similarity_reason}]}. Not cached.
     */
    public function related(Request $request, string $link, LinkService $links, LinkInsightService $ai): JsonResponse
    {
        $model = $links->findOwned($request->user(), $link);

        try {
            $related = $ai->related($request->user(), $model);
        } catch (AiException $e) {
            throw $e->toApiException();
        }

        return ApiResponse::data(['source_link_id' => $model->id, 'related_links' => $related]);
    }
}
