<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Collection;
use App\Support\Api\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared "collection" object (contract 1.2). Build it through
 * CollectionPresenter, which supplies links_count and cover_images in bulk.
 *
 * @mixin Collection
 */
class CollectionResource extends JsonResource
{
    /** @var list<string> */
    private array $coverImages = [];

    /**
     * @param  list<string>  $urls
     */
    public function withCovers(array $urls): static
    {
        $this->coverImages = $urls;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'links_count' => (int) $this->links_count,
            'cover_images' => $this->coverImages,
            'created_at' => ApiDate::format($this->created_at),
            'updated_at' => ApiDate::format($this->updated_at),
        ];
    }
}
