<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| The Stashly client is a native Android app, which does not use CORS. Without
| this file Laravel falls back to a wildcard origin for api/*; security-plan.md
| requires only intended origins. By default NO origin is allowed. If a web
| client is ever added, list its exact origins in CORS_ALLOWED_ORIGINS
| (comma-separated, e.g. "https://app.stashly.example"). Credentials stay off.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    'supports_credentials' => false,

];
