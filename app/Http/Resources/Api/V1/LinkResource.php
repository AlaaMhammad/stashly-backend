<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Collection;
use App\Models\Link;
use App\Models\Tag;
use App\Support\Api\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared "link" object (contract 1.2). Lists eager-load collections and tags;
 * loadMissing only queries for a single link.
 *
 * @mixin Link
 */
class LinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['collections', 'tags']);

        return [
            'id' => $this->id,
            'original_url' => $this->original_url,
            'normalized_url' => $this->normalized_url,
            'source_domain' => $this->source_domain,
            'title' => $this->title,
            'display_title' => $this->displayTitle(),
            'note' => $this->note,
            'is_favorite' => $this->is_favorite,
            'content_type' => $this->content_type,
            'metadata' => [
                'status' => $this->metadata_status,
                'title' => $this->metadata_title,
                'description' => $this->metadata_description,
                'site_name' => $this->metadata_site_name,
                'favicon_url' => $this->metadata_favicon_url,
                'preview_image_url' => $this->metadata_preview_image_url,
                'fetched_at' => $this->metadata_fetched_at ? ApiDate::format($this->metadata_fetched_at) : null,
                'error_code' => $this->metadata_error_code,
            ],
            'collections' => $this->collections->map(fn (Collection $c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            'collections_count' => $this->collections->count(),
            'tags' => $this->tags->map(fn (Tag $t) => $t->name)->values()->all(),
            'created_at' => ApiDate::format($this->created_at),
            'updated_at' => ApiDate::format($this->updated_at),
        ];
    }
}
