<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * API-TAG-001 query: q (prefix), sort (usage|name), limit (max 100).
 */
class ListTagsRequest extends FormRequest
{
    public const SORT_USAGE = 'usage';

    public const SORT_NAME = 'name';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:'.config('stashly.limits.tag_max_length')],
            'sort' => ['nullable', Rule::in([self::SORT_USAGE, self::SORT_NAME])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
