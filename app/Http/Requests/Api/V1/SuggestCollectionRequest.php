<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /ai/suggest-collection (DEC-039): title*, url*, description.
 */
class SuggestCollectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'url:http,https', 'max:'.config('stashly.limits.url_max_length')],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
