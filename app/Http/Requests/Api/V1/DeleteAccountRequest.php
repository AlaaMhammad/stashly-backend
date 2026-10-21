<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-PROF-005: password* for accounts that have one. DEC-036: an account without a
 * password (Google-only) confirms with a fresh Google id_token* instead.
 */
class DeleteAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->user()?->password === null) {
            return [
                'id_token' => ['required', 'string', 'max:8192'],
            ];
        }

        return [
            'password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }
}
