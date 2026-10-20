<?php

namespace App\Support\Api;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Contract timestamp format: ISO 8601 UTC with seconds precision, e.g. 2026-10-16T09:30:12Z.
 */
final class ApiDate
{
    public static function format(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
