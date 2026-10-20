<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\LinkController;
use App\Http\Controllers\Api\V1\LinkMetadataController;
use App\Http\Controllers\Api\V1\LinkPreviewController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProfileController;
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

Route::prefix('auth')->group(function () {
    // API-AUTH-001
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    // API-AUTH-002
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    // API-AUTH-005
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:forgot-password');
    // API-AUTH-006
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);

    Route::middleware('auth:sanctum')->group(function () {
        // API-AUTH-003
        Route::post('/logout', [AuthController::class, 'logout']);
        // API-AUTH-004
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    // API-PROF-001
    Route::get('/me', [ProfileController::class, 'show']);
    // API-PROF-002
    Route::patch('/me', [ProfileController::class, 'update']);
    // API-PROF-005
    Route::delete('/me', [ProfileController::class, 'destroy']);
    // API-AUTH-007
    Route::put('/me/password', [ProfileController::class, 'changePassword']);

    // API-LINK-001
    Route::post('/links/preview', LinkPreviewController::class)->middleware('throttle:link-preview');
    // API-LINK-002
    Route::post('/links', [LinkController::class, 'store'])->middleware('throttle:link-save');
    // API-LINK-003
    Route::get('/links', [LinkController::class, 'index']);
    // API-LINK-004
    Route::get('/links/{link}', [LinkController::class, 'show'])->whereUlid('link');
    // API-LINK-005
    Route::patch('/links/{link}', [LinkController::class, 'update'])->whereUlid('link');
    // API-LINK-006
    Route::delete('/links/{link}', [LinkController::class, 'destroy'])->whereUlid('link');
    // API-LINK-009
    Route::post('/links/{link}/metadata/refresh', [LinkMetadataController::class, 'refresh'])
        ->whereUlid('link')
        ->middleware('throttle:metadata-refresh');
});
