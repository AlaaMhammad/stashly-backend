<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-LINK-008: tags[]* (full replacement). The 20-tag and 40-character
 * limits apply after normalization in TagService.
 */
class ReplaceLinkTagsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tags' => ['present', 'array', 'max:100'],
            'tags.*' => ['string', 'max:200'],
        ];
    }
}
