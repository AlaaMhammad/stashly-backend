<?php

namespace App\Services\Metadata;

interface HttpTransport
{
    /**
     * One GET (or, for the link health check, HEAD) request, pinned to $target->ip, without following redirects.
     *
     * @throws FetchFailure timeout, TLS or connection failure
     */
    public function request(Target $target, float $timeoutSeconds, int $maxBytes, string $method = 'GET'): TransportResponse;
}
