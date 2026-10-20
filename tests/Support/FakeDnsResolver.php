<?php

namespace Tests\Support;

use App\Services\Metadata\DnsResolver;

/**
 * Test DNS: every host resolves to a public address unless configured, so
 * tests never touch real DNS.
 */
class FakeDnsResolver implements DnsResolver
{
    public const PUBLIC_IP = '93.184.216.34';

    /** @var array<string, list<string>> */
    public array $records = [];

    /** @var array<string, list<list<string>>> successive answers per host (DNS rebinding) */
    public array $sequences = [];

    /** @var list<string> */
    public array $calls = [];

    public function resolve(string $host): array
    {
        $this->calls[] = $host;

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        if (! empty($this->sequences[$host])) {
            return array_shift($this->sequences[$host]);
        }

        return $this->records[$host] ?? [self::PUBLIC_IP];
    }
}
