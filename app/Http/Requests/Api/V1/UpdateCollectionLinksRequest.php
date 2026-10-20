<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * API-COLL-006: attach[], detach[] (link ids, max 100 each, at least one non-empty).
 */
class UpdateCollectionLinksRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attach' => ['sometimes', 'nullable', 'array', 'max:100'],
            'attach.*' => ['string'],
            'detach' => ['sometimes', 'nullable', 'array', 'max:100'],
            'detach.*' => ['string'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (empty($this->input('attach')) && empty($this->input('detach'))) {
                    $validator->errors()->add('attach', 'Provide at least one link to attach or detach.');
                }
            },
        ];
    }
}
