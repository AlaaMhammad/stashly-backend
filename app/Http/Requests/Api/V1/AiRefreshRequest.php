<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /links/{link}/key-points and /action-plan (DEC-039): optional ?refresh=true.
 */
class AiRefreshRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'refresh' => ['sometimes', 'in:true,false,1,0'],
        ];
    }

    public function wantsRefresh(): bool
    {
        return $this->boolean('refresh');
    }
}
