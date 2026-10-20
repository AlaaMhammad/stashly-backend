<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-COLL-004: name (1-60, cannot be cleared), description (max 300, null clears).
 */
class UpdateCollectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:'.config('stashly.limits.collection_name_max_length')],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('stashly.limits.collection_description_max_length')],
        ];
    }
}
