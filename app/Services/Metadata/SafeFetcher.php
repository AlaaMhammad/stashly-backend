<?php

namespace App\Services\Metadata;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

/**
 * Fetches one HTML page under the contract's limits: every hop (first request
 * and each redirect) passes MetadataUrlGuard, at most N redirects, one shared
 * time budget for all hops, HTML only, body capped at the size limit.
 */
class SafeFetcher
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly MetadataUrlGuard $guard,
        private readonly HttpTransport $transport,
        private readonly int $maxBytes = 524288,
        private readonly int $maxRedirects = 3,
    ) {}

    /**
     * @throws FetchFailure
     */
    public function fetch(string $url, float $timeoutSeconds): FetchedPage
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $redirects = 0;

        while (true) {
            $target = $this->guard->check($url);

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.05) {
                throw FetchFailure::timeout();
            }

            $response = $this->transport->request($target, $remaining, $this->maxBytes);

            if (in_array($response->status, self::REDIRECT_STATUSES, true)) {
                $location = $response->headers['location'] ?? '';
                if ($location === '') {
                    throw FetchFailure::http($response->status);
                }
                if (++$redirects > $this->maxRedirects) {
                    throw FetchFailure::tooManyRedirects();
                }

                $url = $this->resolve($target->url, $location);

                continue;
            }

            if ($response->status < 200 || $response->status >= 300) {
                throw FetchFailure::http($response->status);
            }
            if (! $response->isHtml()) {
                throw FetchFailure::notHtml();
            }

            return new FetchedPage($target->url, $response->body, $response->contentType(), $response->truncated);
        }
    }

    private function resolve(string $base, string $location): string
    {
        try {
            return (string) UriResolver::resolve(new Uri($base), new Uri($location));
        } catch (InvalidArgumentException) {
            throw FetchFailure::invalidUrl();
        }
    }
}
