<?php

namespace App\Services\Metadata;

interface DnsResolver
{
    /**
     * Every A and AAAA address of the host (an IP literal resolves to itself).
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
