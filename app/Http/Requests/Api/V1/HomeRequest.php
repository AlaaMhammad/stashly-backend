<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-HOME-001 query: recent_limit (default 10, max 20).
 */
class HomeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recent_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
