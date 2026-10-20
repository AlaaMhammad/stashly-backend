<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-LINK-007: collection_ids[]* (full replacement; empty array = Unsorted).
 */
class ReplaceLinkCollectionsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'collection_ids' => ['present', 'array', 'max:'.config('stashly.limits.collections_per_user_max')],
            'collection_ids.*' => ['string'],
        ];
    }
}
