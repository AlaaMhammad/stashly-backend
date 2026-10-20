<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token with a ULID key, so plain-text tokens ("{id}|{secret}") do not
 * reveal how many tokens have been issued (DEC-020).
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUlids;
}
