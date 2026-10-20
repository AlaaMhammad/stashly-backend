<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Link;
use App\Models\Tag;
use App\Services\Links\ContentTypeClassifier;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchFilterController extends Controller
{
    /**
     * API-SRCH-001: 200 {content_types[], sources[], collections[], tags[],
     * date_presets[]}, each option with its count over the user's links.
     * Option shapes are provisional (DEC-028); every "value" is what the
     * matching GET /links parameter accepts.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $links = fn () => Link::query()->whereBelongsTo($user);

        $typeCounts = $links()->toBase()->selectRaw('content_type, count(*) as aggregate')
            ->groupBy('content_type')->pluck('aggregate', 'content_type');

        return ApiResponse::data([
            'content_types' => array_map(
                fn (string $type) => ['value' => $type, 'count' => (int) ($typeCounts[$type] ?? 0)],
                ContentTypeClassifier::TYPES,
            ),
            'sources' => $links()->toBase()
                ->selectRaw('source_domain as value, count(*) as aggregate')
                ->groupBy('source_domain')
                ->orderByDesc('aggregate')->orderBy('source_domain')
                ->get()
                ->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->aggregate])
                ->all(),
            'collections' => Collection::query()->whereBelongsTo($user)->withCount('links')->orderBy('name_key')->get()
                ->map(fn (Collection $c) => ['id' => $c->id, 'name' => $c->name, 'count' => (int) $c->links_count])
                ->all(),
            'tags' => Tag::query()->whereBelongsTo($user)->withCount('links')->orderByDesc('links_count')->orderBy('name')->get()
                ->map(fn (Tag $tag) => ['name' => $tag->name, 'count' => (int) $tag->links_count])
                ->all(),
            'date_presets' => array_map(
                fn (int $days) => ['value' => $days, 'count' => $links()->where('created_at', '>=', now()->subDays($days))->count()],
                [7, 30],
            ),
        ]);
    }
}
