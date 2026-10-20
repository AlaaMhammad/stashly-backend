<?php

namespace App\Http\Middleware;

use App\Support\Api\RequestId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates a server-side request ID for every request. Client-supplied IDs
 * are not trusted, so they cannot be used to forge or inject log entries.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestId::assign();

        return $next($request);
    }
}
