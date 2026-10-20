<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-LINK-002: url*, title, note, collection_ids[], tags[], is_favorite.
 *
 * Collection ownership and tag normalization/limits are checked by LinkService.
 */
class StoreLinkRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:'.config('stashly.limits.title_max_length')],
            'note' => ['nullable', 'string', 'max:'.config('stashly.limits.note_max_length')],
            'is_favorite' => ['sometimes', 'boolean'],
            'collection_ids' => ['sometimes', 'nullable', 'array', 'max:'.config('stashly.limits.collections_per_user_max')],
            'collection_ids.*' => ['string'],
            // Raw bound only; the 20-tag limit (LIMIT_EXCEEDED) applies after normalization.
            'tags' => ['sometimes', 'nullable', 'array', 'max:100'],
            'tags.*' => ['string', 'max:200'],
        ];
    }
}
