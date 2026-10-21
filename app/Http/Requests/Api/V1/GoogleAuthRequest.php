<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-AUTH-008 (DEC-035): id_token*, device_name*, terms_accepted, terms_version.
 * terms_accepted must be true only when the Google account has no Stashly account yet;
 * that depends on the verified token, so the controller enforces it after verification.
 */
class GoogleAuthRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'max:8192'],
            'device_name' => ['required', 'string', 'max:255'],
            'terms_accepted' => ['sometimes', 'nullable', 'boolean'],
            'terms_version' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
