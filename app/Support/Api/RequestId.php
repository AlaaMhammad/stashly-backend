<?php

namespace App\Support\Api;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Per-request correlation ID. Stored in Laravel's Context so every log
 * entry written during the request carries it automatically.
 */
final class RequestId
{
    public const CONTEXT_KEY = 'request_id';

    public static function assign(): string
    {
        $id = (string) Str::ulid();

        Context::add(self::CONTEXT_KEY, $id);

        return $id;
    }

    public static function current(): string
    {
        return Context::get(self::CONTEXT_KEY) ?? self::assign();
    }
}
