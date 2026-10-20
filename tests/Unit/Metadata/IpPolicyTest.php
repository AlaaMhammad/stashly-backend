<?php

namespace Tests\Unit\Metadata;

use App\Services\Metadata\IpPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * security/ssrf.md: private, loopback, link-local, reserved, multicast,
 * unspecified and cloud-metadata addresses are never fetched; IPv4-mapped
 * IPv6 and other transition forms are rejected.
 */
class IpPolicyTest extends TestCase
{
    #[DataProvider('blocked')]
    public function test_blocks(string $ip): void
    {
        $this->assertFalse(IpPolicy::isPublic($ip), "$ip must not be fetchable");
    }

    public static function blocked(): array
    {
        $ips = [
            // IPv4 private, loopback, link-local, metadata, CGNAT, reserved, multicast, broadcast
            '0.0.0.0', '0.1.2.3', '10.0.0.1', '10.255.255.255', '127.0.0.1', '127.255.255.254',
            '169.254.169.254', '169.254.0.1', '172.16.0.1', '172.31.255.255', '192.168.0.1',
            '192.168.255.255', '100.64.0.1', '100.100.100.200', '100.127.255.255', '192.0.0.192',
            '192.0.2.1', '198.18.0.1', '198.19.255.255', '198.51.100.1', '203.0.113.1',
            '224.0.0.1', '239.255.255.255', '240.0.0.1', '255.255.255.255', '168.63.129.16',
            // IPv6 unspecified, loopback, ULA (incl. AWS metadata), link-local, multicast
            '::', '::1', 'fc00::1', 'fd00:ec2::254', 'fe80::1', 'ff02::1',
            // IPv4-mapped / compatible (would reach IPv4 private space)
            '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::ffff:8.8.8.8', '::7f00:1',
            // transition and documentation ranges
            '64:ff9b::7f00:1', '64:ff9b::808:808', '2002:7f00:1::1', '2002:a00:1::1', '2001::1',
            '2001:db8::1', '3fff::1',
            // not an address at all
            '', 'localhost', '1.2.3', '1.2.3.4.5', '999.1.1.1', '0x7f.0.0.1', '2130706433', 'fe80::1%eth0',
        ];

        return array_combine($ips, array_map(fn ($ip) => [$ip], $ips));
    }

    #[DataProvider('allowed')]
    public function test_allows_public_addresses(string $ip): void
    {
        $this->assertTrue(IpPolicy::isPublic($ip), "$ip should be fetchable");
    }

    public static function allowed(): array
    {
        $ips = [
            '8.8.8.8', '1.1.1.1', '93.184.216.34', '172.15.255.255', '172.32.0.1', '100.63.255.255',
            '100.128.0.1', '169.253.255.255', '169.255.0.1', '192.167.255.255', '192.169.0.1',
            '223.255.255.255', '2606:4700:4700::1111', '2a00:1450:4001::200e', '2001:4860:4860::8888',
            '2400:cb00::1',
        ];

        return array_combine($ips, array_map(fn ($ip) => [$ip], $ips));
    }
}
