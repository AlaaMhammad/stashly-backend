<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Support\Api\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared "user" object: id, display_name, email, created_at.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'created_at' => ApiDate::format($this->created_at),
        ];
    }
}
