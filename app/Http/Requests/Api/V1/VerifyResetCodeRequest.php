<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;

/**
 * API-AUTH-009 (DEC-040): email*, code* (6 digits).
 */
class VerifyResetCodeRequest extends FormRequest
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
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeEmail();

        $code = $this->input('code');

        if (is_int($code)) {
            $code = (string) $code;
        }

        if (is_string($code)) {
            $this->merge(['code' => preg_replace('/\s+/', '', $code)]);
        }
    }
}
