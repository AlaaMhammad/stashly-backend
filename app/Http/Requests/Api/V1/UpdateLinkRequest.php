<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * API-LINK-005: title, note, is_favorite (all optional). Null or empty clears
 * title/note. Any other field is read-only and rejected (SEC-005).
 */
class UpdateLinkRequest extends FormRequest
{
    public const EDITABLE = ['title', 'note', 'is_favorite'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:'.config('stashly.limits.title_max_length')],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.config('stashly.limits.note_max_length')],
            'is_favorite' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Body fields only; query-string parameters are not edits.
                foreach (array_keys($this->getInputSource()->all()) as $field) {
                    if (! in_array($field, self::EDITABLE, true)) {
                        $validator->errors()->add($field, 'This field is read-only.');
                    }
                }
            },
        ];
    }
}
