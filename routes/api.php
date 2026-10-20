<?php

use App\Http\Controllers\Api\V1\SystemController;
use Illuminate\Support\Facades\Route;

// All routes here are served under /api/v1 (apiPrefix in bootstrap/app.php).
// Endpoint IDs refer to Stashly_Backend_Docs/api/endpoints.md.

// API-SYS-001. Not throttled: the limiter depends on the cache, and /health
// must still answer 503 when the cache is down (DEC-019).
Route::get('/health', [SystemController::class, 'health'])
    ->withoutMiddleware('throttle:api');

// API-SYS-002
Route::get('/config', [SystemController::class, 'config']);
