<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
