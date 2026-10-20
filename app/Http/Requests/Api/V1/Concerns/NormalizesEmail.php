<?php

namespace App\Http\Requests\Api\V1\Concerns;

use Illuminate\Support\Str;

/**
 * Emails are stored and looked up trimmed and lowercased, so account lookups
 * and uniqueness behave the same on MySQL (case-insensitive collation) and SQLite.
 */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
