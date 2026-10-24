<?php

namespace App\Services\Links;

use App\Services\Metadata\FetchFailure;
use App\Services\Metadata\HttpTransport;
use App\Services\Metadata\MetadataUrlGuard;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

/**
 * On-demand "is this link still alive?" check (DEC-041). One HEAD request (a GET when the server
 * refuses HEAD with 405/501), at most 3 redirects, one 5 second budget, and every hop passes the same
 * SSRF guard and IP-pinned transport as the metadata fetch. Never reads a body.
 */
class LinkHealthChecker
{
    /** Alive although not "OK": the server answered but wants credentials, blocks bots or limits us. */
    private const RESTRICTED = [401, 403, 429];

    private const REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly MetadataUrlGuard $guard,
        private readonly HttpTransport $transport,
    ) {}

    public function check(string $url): HealthResult
    {
        $started = microtime(true);
        $deadline = $started + (float) config('stashly.health.timeout_seconds');
        $maxRedirects = (int) config('stashly.health.max_redirects');
        $method = 'HEAD';
        $redirects = 0;

        try {
            while (true) {
                $target = $this->guard->check($url);

                $remaining = $deadline - microtime(true);
                if ($remaining <= 0.05) {
                    throw FetchFailure::timeout();
                }

                $response = $this->transport->request($target, $remaining, 1024, $method);

                if (in_array($response->status, self::REDIRECTS, true) && ($response->headers['location'] ?? '') !== '') {
                    if (++$redirects > $maxRedirects) {
                        throw FetchFailure::tooManyRedirects();
                    }
                    $url = $this->resolve($target->url, $response->headers['location']);

                    continue;
                }

                if ($method === 'HEAD' && in_array($response->status, [405, 501], true)) {
                    // The server does not do HEAD; ask again with GET (the body is not read).
                    $method = 'GET';

                    continue;
                }

                return $this->result($response->status, $started);
            }
        } catch (FetchFailure $e) {
            return match ($e->errorCode) {
                FetchFailure::TIMEOUT => $this->failed('timeout', $started),
                FetchFailure::TLS_ERROR => $this->failed('tls_error', $started),
                FetchFailure::TOO_MANY_REDIRECTS => $this->failed('too_many_redirects', $started),
                FetchFailure::UNREACHABLE => $this->failed('unreachable', $started),
                // Not allowed to look (private address, odd port, unparseable URL): that says nothing about the link.
                default => new HealthResult(null, $this->elapsed($started), null, 'blocked'),
            };
        }
    }

    private function result(int $status, float $started): HealthResult
    {
        $alive = $status < 400 || in_array($status, self::RESTRICTED, true) || in_array($status, [405, 501], true);

        return new HealthResult($status, $this->elapsed($started), ! $alive, $alive ? null : 'http_'.$status);
    }

    private function failed(string $error, float $started): HealthResult
    {
        return new HealthResult(null, $this->elapsed($started), true, $error);
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * @throws FetchFailure
     */
    private function resolve(string $base, string $location): string
    {
        try {
            return (string) UriResolver::resolve(new Uri($base), new Uri($location));
        } catch (InvalidArgumentException) {
            throw FetchFailure::invalidUrl();
        }
    }
}
