<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * API-COLL-001 query: sort (name|recent), limit (default 50, max 100), cursor.
 */
class ListCollectionsRequest extends FormRequest
{
    public const SORT_NAME = 'name';

    public const SORT_RECENT = 'recent';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sort' => ['nullable', Rule::in([self::SORT_NAME, self::SORT_RECENT])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
