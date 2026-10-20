<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-LINK-002: url*, title, note, collection_ids[], tags[], is_favorite.
 *
 * collection_ids and tags must be empty until collections and tags exist
 * (Phase 5). They are rejected rather than silently dropped (DEC-024).
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
            'collection_ids' => ['sometimes', 'nullable', 'array', 'max:0'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collection_ids.max' => 'Assigning collections while saving is not available yet.',
            'tags.max' => 'Assigning tags while saving is not available yet.',
        ];
    }
}
