<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-LINK-001: url*. Scheme, length, host and credential checks happen in
 * UrlNormalizer and answer INVALID_URL rather than VALIDATION_FAILED.
 */
class PreviewLinkRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string'],
        ];
    }
}
