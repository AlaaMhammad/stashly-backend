<?php

use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ForceJsonResponse;
use App\Support\Api\ApiExceptionRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->api(prepend: [ForceJsonResponse::class], append: [ApiSecurityHeaders::class]);
        // Rate-limit keys use the client IP, so only proxies listed in TRUSTED_PROXIES
        // may set it (X-Forwarded-For). Unset = trust none, which is safe but wrong behind a load balancer.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? array_map('trim', explode(',', env('TRUSTED_PROXIES'))) : null);
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The default report of a QueryException contains the SQL with its bound values
        // (e-mails, password hashes, tokens). Log only the SQLSTATE and connection.
        $exceptions->report(function (QueryException $e) {
            Log::error('Database query failed.', [
                'sqlstate' => (string) $e->getCode(),
                'connection' => $e->connectionName,
                'exception' => $e::class,
            ]);
        })->stop();

        $exceptions->render(
            fn (Throwable $e, Request $request) => (new ApiExceptionRenderer)->render($e, $request)
        );
    })->create();
