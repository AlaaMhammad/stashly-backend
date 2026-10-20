<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * API-AUTH-001: display_name*, email*, password* (8-128), password_confirmation*,
 * terms_accepted* (true), terms_version, device_name.
 */
class RegisterRequest extends FormRequest
{
    use NormalizesEmail;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:1', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            'terms_accepted' => ['required', 'boolean', 'accepted'],
            'terms_version' => ['nullable', 'string', 'max:50'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
