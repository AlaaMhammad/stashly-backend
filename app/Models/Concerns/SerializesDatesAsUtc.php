<?php

namespace App\Models\Concerns;

use App\Support\Api\ApiDate;
use DateTimeInterface;

/**
 * Serializes model dates in the contract format (2026-10-16T09:30:12Z).
 */
trait SerializesDatesAsUtc
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return ApiDate::format($date);
    }
}
