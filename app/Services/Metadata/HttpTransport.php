<?php

namespace App\Services\Metadata;

interface HttpTransport
{
    /**
     * One GET request, pinned to $target->ip, without following redirects.
     *
     * @throws FetchFailure timeout, TLS or connection failure
     */
    public function request(Target $target, float $timeoutSeconds, int $maxBytes): TransportResponse;
}
