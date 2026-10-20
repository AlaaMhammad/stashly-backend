<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * API-AUTH-003: all_devices (bool, default false).
 */
class LogoutRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'all_devices' => ['sometimes', 'boolean'],
        ];
    }
}
