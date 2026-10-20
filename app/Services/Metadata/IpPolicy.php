<?php

namespace App\Services\Metadata;

/**
 * Which addresses the metadata fetcher may connect to (security/ssrf.md).
 *
 * IPv4: everything except explicitly listed non-public ranges (private,
 * loopback, link-local incl. 169.254.169.254, CGNAT, documentation,
 * benchmarking, multicast, reserved, broadcast, cloud metadata hosts).
 * IPv6: allow-list of global unicast 2000::/3 minus transition and
 * documentation ranges, so ::1, fc00::/7, fe80::/10, ff00::/8, IPv4-mapped
 * (::ffff:0:0/96), NAT64 (64:ff9b::/96), 6to4 and Teredo are all rejected.
 */
final class IpPolicy
{
    private const V4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // Azure WireServer: a public-looking address that serves instance metadata.
        '168.63.129.16/32',
    ];

    private const V6_ALLOWED = ['2000::/3'];

    private const V6_BLOCKED = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];

    public static function isPublic(string $ip): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 4) {
            return ! self::inAny($binary, self::V4_BLOCKED);
        }

        return self::inAny($binary, self::V6_ALLOWED) && ! self::inAny($binary, self::V6_BLOCKED);
    }

    /**
     * @param  list<string>  $cidrs
     */
    private static function inAny(string $binary, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $networkBinary = inet_pton($network);

            if (strlen($networkBinary) !== strlen($binary)) {
                continue;
            }

            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;

            if (substr($binary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($binary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
