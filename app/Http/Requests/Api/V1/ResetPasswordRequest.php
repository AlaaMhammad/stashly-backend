<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;

/**
 * API-AUTH-006 (DEC-040): email*, code* (6 digits), password*, password_confirmation*.
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
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeEmail();
        $this->normalizeCode();
    }

    private function normalizeCode(): void
    {
        $code = $this->input('code');

        if (is_int($code)) {
            $code = (string) $code;
        }

        if (is_string($code)) {
            $this->merge(['code' => preg_replace('/\s+/', '', $code)]);
        }
    }
}
