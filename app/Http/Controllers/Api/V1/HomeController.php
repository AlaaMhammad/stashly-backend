<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\HomeRequest;
use App\Http\Resources\Api\V1\LinkResource;
use App\Services\Collections\CollectionPresenter;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    /** Provisional quick_access size (DEC-029). */
    private const QUICK_ACCESS_COUNT = 4;

    /**
     * API-HOME-001: 200 {user{display_name}, links_count, favorites_count,
     * quick_access[collection], recent_links[link]}.
     *
     * quick_access: the most recently active collections (updated, or links
     * added/removed), newest first (DEC-029).
     */
    public function __invoke(HomeRequest $request, CollectionPresenter $presenter): JsonResponse
    {
        $user = $request->user();

        $recent = $user->links()->with(['collections', 'tags'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit((int) $request->validated('recent_limit', 10))
            ->get();

        $quickAccess = $user->collections()
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(self::QUICK_ACCESS_COUNT)
            ->get();

        return ApiResponse::data([
            'user' => ['display_name' => $user->display_name],
            'links_count' => $user->links()->count(),
            'favorites_count' => $user->links()->where('is_favorite', true)->count(),
            'quick_access' => $presenter->many($quickAccess, $request),
            'recent_links' => LinkResource::collection($recent)->resolve($request),
        ]);
    }
}
