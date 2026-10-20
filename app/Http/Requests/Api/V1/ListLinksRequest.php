<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Links\ContentTypeClassifier;
use App\Services\Links\LinkFilters;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * API-LINK-003 query: q, favorite, content_type, source, collection_id, tags,
 * saved_within_days (7|30), sort (newest|oldest), limit (1-50), cursor.
 *
 * content_type and source take comma lists (OR). collection_id and tags are
 * rejected until collections and tags exist (Phase 5, DEC-024).
 */
class ListLinksRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'favorite' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
            'content_type' => ['bail', 'nullable', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail) {
                if (array_diff(LinkFilters::list($value), ContentTypeClassifier::TYPES) !== []) {
                    $fail('The content type must be one of: '.implode(', ', ContentTypeClassifier::TYPES).'.');
                }
            }],
            'source' => ['nullable', 'string', 'max:2000'],
            'collection_id' => ['prohibited'],
            'tags' => ['prohibited'],
            'saved_within_days' => ['nullable', Rule::in(['7', '30'])],
            'sort' => ['nullable', Rule::in([LinkFilters::SORT_NEWEST, LinkFilters::SORT_OLDEST])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('stashly.limits.page_size_max')],
            'cursor' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collection_id.prohibited' => 'Filtering by collection is not available yet.',
            'tags.prohibited' => 'Filtering by tags is not available yet.',
        ];
    }
}
