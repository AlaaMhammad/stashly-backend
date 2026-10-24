<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /links/broken (DEC-041): limit, cursor.
 */
class BrokenLinksRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('stashly.limits.page_size_max')],
            'cursor' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
