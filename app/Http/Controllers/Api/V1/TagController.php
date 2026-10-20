<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListTagsRequest;
use App\Models\Tag;
use App\Services\Tags\TagService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class TagController extends Controller
{
    /**
     * API-TAG-001: 200 {data:[{name, links_count}]}. Default sort "usage"
     * (most used first, then name); default limit 100 (DEC-028).
     */
    public function index(ListTagsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $prefix = TagService::normalize((string) ($validated['q'] ?? ''));

        $tags = Tag::query()
            ->whereBelongsTo($request->user())
            ->withCount('links')
            ->when($prefix !== '', fn ($query) => $query->whereRaw(
                "name LIKE ? ESCAPE '!'",
                [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%'],
            ))
            ->when(
                ($validated['sort'] ?? ListTagsRequest::SORT_USAGE) === ListTagsRequest::SORT_USAGE,
                fn ($query) => $query->orderByDesc('links_count'),
            )
            ->orderBy('name')
            ->limit((int) ($validated['limit'] ?? 100))
            ->get();

        return ApiResponse::data($tags->map(fn (Tag $tag) => [
            'name' => $tag->name,
            'links_count' => (int) $tag->links_count,
        ])->all());
    }
}
