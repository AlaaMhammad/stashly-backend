<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;

/**
 * API-AUTH-002: email*, password*, device_name.
 * No email format rule: a malformed email gets the same INVALID_CREDENTIALS answer.
 */
class LoginRequest extends FormRequest
{
    use NormalizesEmail;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
