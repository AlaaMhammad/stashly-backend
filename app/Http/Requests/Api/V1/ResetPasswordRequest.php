<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;

/**
 * API-AUTH-006: email*, token*, password*, password_confirmation*.
 */
class ResetPasswordRequest extends FormRequest
{
    use NormalizesEmail;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ];
    }
}
