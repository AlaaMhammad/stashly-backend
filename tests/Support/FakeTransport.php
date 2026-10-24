<?php

namespace Tests\Support;

use App\Services\Metadata\FetchFailure;
use App\Services\Metadata\HttpTransport;
use App\Services\Metadata\Target;
use App\Services\Metadata\TransportResponse;

/**
 * Test transport: serves canned responses keyed by the requested URL and
 * records every request. Unconfigured URLs are unreachable.
 */
class FakeTransport implements HttpTransport
{
    /** @var array<string, list<TransportResponse|FetchFailure>> */
    private array $routes = [];

    /** @var list<array{target: Target, timeout: float, maxBytes: int, method: string}> */
    public array $requests = [];

    /** Seconds each request pretends to take (really sleeps), for budget tests. */
    public float $delay = 0;

    public function on(string $url, TransportResponse|FetchFailure ...$responses): static
    {
        $this->routes[$url] = $responses;

        return $this;
    }

    public function request(Target $target, float $timeoutSeconds, int $maxBytes, string $method = 'GET'): TransportResponse
    {
        $this->requests[] = ['target' => $target, 'timeout' => $timeoutSeconds, 'maxBytes' => $maxBytes, 'method' => $method];

        if ($this->delay > 0) {
            usleep((int) ($this->delay * 1_000_000));
        }

        $queue = &$this->routes[$target->url];
        if (empty($queue)) {
            throw FetchFailure::unreachable();
        }
        $next = count($queue) > 1 ? array_shift($queue) : $queue[0];

        if ($next instanceof FetchFailure) {
            throw $next;
        }

        return $next;
    }

    public static function html(string $body, int $status = 200, string $contentType = 'text/html; charset=utf-8'): TransportResponse
    {
        return new TransportResponse($status, ['content-type' => $contentType], $body);
    }

    public static function redirect(string $location, int $status = 302): TransportResponse
    {
        return new TransportResponse($status, ['location' => $location]);
    }

    public static function status(int $status, string $contentType = 'text/html'): TransportResponse
    {
        return new TransportResponse($status, ['content-type' => $contentType]);
    }
}
