<?php

namespace App\Services\Links;

/**
 * Outcome of one health check. isBroken is null when the link could not be checked at all
 * (blocked target): the stored state must then stay as it is.
 */
final class HealthResult
{
    public function __construct(
        public readonly ?int $status,
        public readonly int $responseTimeMs,
        public readonly ?bool $isBroken,
        public readonly ?string $error,
    ) {}
}
