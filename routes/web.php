<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Contract section 5 / DEC-021: Terms and Privacy pages. DRAFT text (version draft-1) until
// final legal copy is supplied; GET /api/v1/config points the app here unless the
// STASHLY_TERMS_URL / STASHLY_PRIVACY_URL environment variables say otherwise.
foreach (['terms' => 'legal.terms', 'privacy' => 'legal.privacy'] as $path => $view) {
    Route::get("/$path", fn () => response()->view($view)->withHeaders([
        'Cache-Control' => 'public, max-age=300',
        'X-Content-Type-Options' => 'nosniff',
    ]))->name($path);
}

// Password-reset page for the 6-digit code (DEC-040): e-mail, code and new password; it calls
// POST /api/v1/auth/reset-password. The Android app does the same in-app and never opens this page.
Route::get('/reset-password', function (Request $request) {
    return response()
        ->view('auth.reset-password', [
            'email' => is_string($request->query('email')) ? $request->query('email') : '',
        ])
        ->withHeaders([
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
        ]);
})->name('password.reset');
