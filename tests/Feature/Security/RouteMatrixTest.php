<?php

namespace Tests\Feature\Security;

use Illuminate\Cache\RateLimiter;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Release gate: the 32 contract operations exist exactly once, each with the
 * contract's authentication and rate limiter (API reference 1.1 and 2.x).
 */
class RouteMatrixTest extends TestCase
{
    /** Operations that need no bearer token. */
    private const PUBLIC = [
        'GET api/v1/health', 'GET api/v1/config', 'POST api/v1/auth/register', 'POST api/v1/auth/login',
        'POST api/v1/auth/forgot-password', 'POST api/v1/auth/reset-password',
    ];

    /** Limiters beyond the default 120/min/user "api" limiter. */
    private const EXTRA_LIMITERS = [
        'POST api/v1/auth/register' => ['register'],
        'POST api/v1/auth/login' => ['login'],
        'POST api/v1/auth/forgot-password' => ['forgot-password'],
        'POST api/v1/links/preview' => ['link-preview'],
        'POST api/v1/links' => ['link-save'],
        'POST api/v1/links/{link}/metadata/refresh' => ['metadata-refresh'],
        'GET api/v1/me/export' => ['export'],
    ];

    private const OPERATIONS = [
        'GET api/v1/health', 'GET api/v1/config',
        'POST api/v1/auth/register', 'POST api/v1/auth/login', 'POST api/v1/auth/logout', 'POST api/v1/auth/refresh',
        'POST api/v1/auth/forgot-password', 'POST api/v1/auth/reset-password', 'PUT api/v1/me/password',
        'GET api/v1/me', 'PATCH api/v1/me', 'GET api/v1/me/stats', 'GET api/v1/me/export', 'DELETE api/v1/me',
        'POST api/v1/links/preview', 'POST api/v1/links', 'GET api/v1/links', 'GET api/v1/links/{link}',
        'PATCH api/v1/links/{link}', 'DELETE api/v1/links/{link}', 'PUT api/v1/links/{link}/collections',
        'PUT api/v1/links/{link}/tags', 'POST api/v1/links/{link}/metadata/refresh',
        'GET api/v1/collections', 'POST api/v1/collections', 'GET api/v1/collections/{collection}',
        'PATCH api/v1/collections/{collection}', 'DELETE api/v1/collections/{collection}',
        'PATCH api/v1/collections/{collection}/links',
        'GET api/v1/tags', 'GET api/v1/search/filters', 'GET api/v1/home',
    ];

    /** @return array<string, Route> keyed by "METHOD uri" */
    private function apiRoutes(): array
    {
        $routes = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = "$method {$route->uri()}";
                $this->assertArrayNotHasKey($key, $routes, "Duplicate route $key");
                $routes[$key] = $route;
            }
        }

        return $routes;
    }

    /** @return list<string> */
    private function middleware(Route $route): array
    {
        return app('router')->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());
    }

    public function test_exactly_the_32_contract_operations_are_routed(): void
    {
        $this->assertCount(32, self::OPERATIONS);
        $this->assertEqualsCanonicalizing(self::OPERATIONS, array_keys($this->apiRoutes()));
    }

    public function test_every_operation_has_the_contract_authentication(): void
    {
        foreach ($this->apiRoutes() as $key => $route) {
            $authenticated = in_array('Illuminate\Auth\Middleware\Authenticate:sanctum', $this->middleware($route), true);

            $this->assertSame(! in_array($key, self::PUBLIC, true), $authenticated, "$key authentication");
        }
    }

    public function test_every_operation_has_the_contract_rate_limiters(): void
    {
        foreach ($this->apiRoutes() as $key => $route) {
            preg_match_all('/ThrottleRequests:([\w-]+)/', implode(',', $this->middleware($route)), $m);

            $expected = $key === 'GET api/v1/health' ? [] : array_merge(['api'], self::EXTRA_LIMITERS[$key] ?? []);

            $this->assertEqualsCanonicalizing($expected, $m[1], "$key rate limiters");
        }
    }

    public function test_the_named_limiters_exist_with_the_contract_numbers(): void
    {
        foreach (['api', 'login', 'register', 'forgot-password', 'link-preview', 'link-save', 'export', 'metadata-refresh'] as $name) {
            $this->assertNotNull(app(RateLimiter::class)->limiter($name), "limiter $name");
        }
    }

    public function test_no_unlisted_routes_expose_data(): void
    {
        $routes = collect(RouteFacade::getRoutes())->map(fn ($r) => $r->uri())->reject(fn ($u) => str_starts_with($u, 'api/v1/'));

        // Web routes outside the contract: welcome page, framework health probe, the reset-password
        // page and the draft legal pages (DEC-021). The csrf-cookie and local-storage routes are disabled (DEC-016).
        $this->assertEqualsCanonicalizing(['/', 'up', 'reset-password', 'terms', 'privacy'], $routes->values()->all());
    }
}
