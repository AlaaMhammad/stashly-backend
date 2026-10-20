<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-COLL-002: name* (1-60), description (max 300).
 */
class StoreCollectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.config('stashly.limits.collection_name_max_length')],
            'description' => ['nullable', 'string', 'max:'.config('stashly.limits.collection_description_max_length')],
        ];
    }
}
