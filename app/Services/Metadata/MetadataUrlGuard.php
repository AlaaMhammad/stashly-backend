<?php

namespace App\Services\Metadata;

use App\Services\Links\InvalidUrl;
use App\Services\Links\UrlNormalizer;

/**
 * Validates a URL right before it is requested (and again for every redirect):
 * normalization rules (http/https, no credentials, length), ports 80/443 only,
 * then DNS. The host is rejected if ANY of its addresses is non-public, and the
 * checked address is returned so the connection can be pinned to it.
 */
class MetadataUrlGuard
{
    private const ALLOWED_PORTS = [80, 443];

    public function __construct(
        private readonly UrlNormalizer $normalizer,
        private readonly DnsResolver $resolver,
    ) {}

    /**
     * @throws FetchFailure
     */
    public function check(string $url): Target
    {
        try {
            $fetchUrl = $this->normalizer->normalize($url)->fetchUrl;
        } catch (InvalidUrl) {
            throw FetchFailure::invalidUrl();
        }

        $parts = parse_url($fetchUrl);
        $scheme = $parts['scheme'] ?? '';
        $host = trim($parts['host'] ?? '', '[]');

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw FetchFailure::invalidUrl();
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            throw FetchFailure::blockedPort();
        }

        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw FetchFailure::unreachable();
        }
        foreach ($ips as $ip) {
            if (! IpPolicy::isPublic($ip)) {
                throw FetchFailure::blockedHost();
            }
        }

        // Prefer IPv4 when both exist; the chosen address is the one that was checked.
        usort($ips, fn (string $a, string $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        return new Target($fetchUrl, $host, $port, $ips[0]);
    }
}
