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

// Contract section 5: password-reset page linked from the reset email.
// It calls POST /api/v1/auth/reset-password; the token never leaves via Referer.
Route::get('/reset-password', function (Request $request) {
    return response()
        ->view('auth.reset-password', [
            'token' => is_string($request->query('token')) ? $request->query('token') : '',
            'email' => is_string($request->query('email')) ? $request->query('email') : '',
        ])
        ->withHeaders([
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
        ]);
})->name('password.reset');
