<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers for every API response: no MIME sniffing, no referrer, and no
 * caching unless the endpoint opted in with "public" (GET /config). HSTS is
 * sent only over HTTPS in production.
 *
 * Errors are rendered outside the middleware stack (a 401 from the auth
 * middleware never returns through it), so ApiError applies the same headers.
 */
class ApiSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request), $request);
    }

    public static function apply(Response $response, Request $request): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        if (! $response->headers->hasCacheControlDirective('public')) {
            $response->headers->set('Cache-Control', 'no-store');
        }
        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
