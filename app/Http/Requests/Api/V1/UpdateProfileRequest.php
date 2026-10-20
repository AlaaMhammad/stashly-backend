<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-PROF-002: display_name* (1-100). Email cannot be changed in v1, so
 * sending it is rejected rather than silently ignored.
 */
class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:1', 'max:100'],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }
}
