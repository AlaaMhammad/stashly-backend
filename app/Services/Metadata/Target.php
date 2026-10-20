<?php

namespace App\Services\Metadata;

/**
 * A request target that passed the guard: the URL to request and the
 * checked public IP that the connection must be pinned to.
 */
final class Target
{
    public function __construct(
        public readonly string $url,
        /** Hostname for Host header, SNI and certificate checks (no brackets). */
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
    ) {}

    public function hostIsLiteral(): bool
    {
        return filter_var($this->host, FILTER_VALIDATE_IP) !== false;
    }
}
