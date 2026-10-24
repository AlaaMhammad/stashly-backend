<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Links\LinkFilters;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * GET /links/search (DEC-041): query, collection_id, tag, is_favorite, is_archived, has_ai_summary,
 * date_from, date_to (ISO-8601 date or date-time), sort (created_at|title), direction (asc|desc), limit, cursor.
 * sort=last_viewed_at is not available: the backend does not record when a link is opened.
 */
class SearchLinksRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $flag = ['nullable', Rule::in(['true', 'false', '1', '0'])];
        $isoDate = ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, Closure $fail) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value) !== 1) {
                $fail("The $attribute must be an ISO-8601 date (2026-10-01) or date-time (2026-10-01T09:30:00Z).");

                return;
            }
            try {
                Carbon::parse($value);
            } catch (Throwable) {
                $fail("The $attribute is not a valid date.");
            }
        }];

        return [
            'query' => ['nullable', 'string', 'max:200'],
            'collection_id' => ['nullable', 'string', 'max:2000'],
            'tag' => ['nullable', 'string', 'max:100'],
            'is_favorite' => $flag,
            'is_archived' => $flag,
            'has_ai_summary' => $flag,
            'date_from' => $isoDate,
            'date_to' => $isoDate,
            'sort' => ['nullable', Rule::in(['created_at', 'title'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('stashly.limits.page_size_max')],
            'cursor' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $from = $this->input('date_from');
            $to = $this->input('date_to');

            if (is_string($from) && is_string($to) && ! $validator->errors()->hasAny(['date_from', 'date_to'])
                && LinkFilters::date($from, false)->gt(LinkFilters::date($to, true))) {
                $validator->errors()->add('date_to', 'The date_to must not be before date_from.');
            }
        });
    }
}
