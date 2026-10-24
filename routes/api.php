<?php

use App\Http\Controllers\Api\V1\AskAiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\CollectionSharingController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\LinkController;
use App\Http\Controllers\Api\V1\LinkInsightsController;
use App\Http\Controllers\Api\V1\LinkLibraryController;
use App\Http\Controllers\Api\V1\LinkMetadataController;
use App\Http\Controllers\Api\V1\LinkPreviewController;
use App\Http\Controllers\Api\V1\LinkRelationsController;
use App\Http\Controllers\Api\V1\LinkSummarizeController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SearchFilterController;
use App\Http\Controllers\Api\V1\SuggestCollectionController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\TagController;
use Illuminate\Support\Facades\Route;

// All routes here are served under /api/v1 (apiPrefix in bootstrap/app.php).
// Endpoint IDs refer to Stashly_Backend_Docs/api/endpoints.md.

// API-SYS-001. Not throttled: the limiter depends on the cache, and /health
// must still answer 503 when the cache is down (DEC-019).
Route::get('/health', [SystemController::class, 'health'])
    ->withoutMiddleware('throttle:api');

// API-SYS-002
Route::get('/config', [SystemController::class, 'config']);

// API-PUB-001 (DEC-041): public read-only collection feed, no token.
Route::get('/public/collections/{slug}', [CollectionSharingController::class, 'show'])
    ->where('slug', '[A-Za-z0-9-]{1,80}')
    ->middleware('throttle:public-feed');

Route::prefix('auth')->group(function () {
    // API-AUTH-001
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    // API-AUTH-002
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    // API-AUTH-008 (DEC-035)
    Route::post('/google', GoogleAuthController::class)->middleware('throttle:google-auth');
    // API-AUTH-005
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:forgot-password');
    // API-AUTH-006
    // API-AUTH-009 (DEC-040)
    Route::post('/verify-reset-code', [PasswordResetController::class, 'verify'])->middleware('throttle:reset-code');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:reset-code');

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
    // API-LINK-010..013 (DEC-041): fixed paths, registered before /links/{link}.
    Route::post('/links/import', [LinkLibraryController::class, 'import'])->middleware('throttle:import');
    Route::get('/links/export', [LinkLibraryController::class, 'export'])->middleware('throttle:links-export');
    Route::get('/links/search', [LinkLibraryController::class, 'search']);
    Route::get('/links/broken', [LinkLibraryController::class, 'broken']);
    // API-LINK-003
    Route::get('/links', [LinkController::class, 'index']);
    // API-LINK-004
    Route::get('/links/{link}', [LinkController::class, 'show'])->whereUlid('link');
    // API-LINK-005
    Route::patch('/links/{link}', [LinkController::class, 'update'])->whereUlid('link');
    // API-LINK-006
    Route::delete('/links/{link}', [LinkController::class, 'destroy'])->whereUlid('link');
    // API-LINK-007
    Route::put('/links/{link}/collections', [LinkRelationsController::class, 'collections'])->whereUlid('link');
    // API-LINK-008
    Route::put('/links/{link}/tags', [LinkRelationsController::class, 'tags'])->whereUlid('link');
    // API-LINK-014 (DEC-041)
    Route::post('/links/{link}/check-health', [LinkLibraryController::class, 'checkHealth'])
        ->whereUlid('link')
        ->middleware('throttle:check-health');
    // API-LINK-009
    Route::post('/links/{link}/metadata/refresh', [LinkMetadataController::class, 'refresh'])
        ->whereUlid('link')
        ->middleware('throttle:metadata-refresh');

    // API-AI-001 (DEC-038)
    Route::post('/links/{link}/summarize', LinkSummarizeController::class)
        ->whereUlid('link')
        ->middleware('throttle:ai');
    // API-AI-002 (DEC-038)
    Route::post('/ai/ask', AskAiController::class)->middleware('throttle:ai');
    // API-AI-003..005 (DEC-039)
    Route::post('/links/{link}/key-points', [LinkInsightsController::class, 'keyPoints'])->whereUlid('link')->middleware('throttle:ai');
    Route::post('/links/{link}/action-plan', [LinkInsightsController::class, 'actionPlan'])->whereUlid('link')->middleware('throttle:ai');
    Route::get('/links/{link}/related', [LinkInsightsController::class, 'related'])->whereUlid('link')->middleware('throttle:ai');
    // API-AI-006 (DEC-039)
    Route::post('/ai/suggest-collection', SuggestCollectionController::class)->middleware('throttle:ai');

    // API-COLL-001
    Route::get('/collections', [CollectionController::class, 'index']);
    // API-COLL-002
    Route::post('/collections', [CollectionController::class, 'store']);
    // API-COLL-003
    Route::get('/collections/{collection}', [CollectionController::class, 'show'])->whereUlid('collection');
    // API-COLL-004
    Route::patch('/collections/{collection}', [CollectionController::class, 'update'])->whereUlid('collection');
    // API-COLL-005
    Route::delete('/collections/{collection}', [CollectionController::class, 'destroy'])->whereUlid('collection');
    // API-COLL-007 (DEC-041)
    Route::post('/collections/{collection}/share', [CollectionSharingController::class, 'share'])->whereUlid('collection');
    // API-COLL-006
    Route::patch('/collections/{collection}/links', [CollectionController::class, 'updateLinks'])->whereUlid('collection');

    // API-TAG-001
    Route::get('/tags', [TagController::class, 'index']);
    // API-SRCH-001
    Route::get('/search/filters', SearchFilterController::class);
    // API-HOME-001
    Route::get('/home', HomeController::class);
    // API-PROF-004
    Route::get('/me/export', [ProfileController::class, 'export'])->middleware('throttle:export');
    // API-PROF-003
    Route::get('/me/stats', [ProfileController::class, 'stats']);
});
