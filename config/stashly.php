<?php

/*
|--------------------------------------------------------------------------
| Stashly API settings
|--------------------------------------------------------------------------
|
| Values served by GET /api/v1/config and shared limits used by validation.
| Source: Stashly API Reference v1.0. Release-specific values (app versions,
| legal URLs, terms version) come from the environment; see DEC-010.
|
*/

return [

    'api_version' => '1.0',

    'app_versions' => [
        'min_supported' => env('STASHLY_MIN_SUPPORTED_APP_VERSION'),
        'latest' => env('STASHLY_LATEST_APP_VERSION'),
    ],

    'terms' => [
        'version' => env('STASHLY_TERMS_VERSION'),
        'url' => env('STASHLY_TERMS_URL'),
    ],

    'privacy' => [
        'url' => env('STASHLY_PRIVACY_URL'),
    ],

    // Contract "Limits" row. Key names are provisional until DEC-008 is resolved.
    'limits' => [
        'url_max_length' => 2048,
        'title_max_length' => 300,
        'note_max_length' => 2000,
        'collection_name_max_length' => 60,
        'collection_description_max_length' => 300,
        'tag_max_length' => 40,
        'tags_per_link_max' => 20,
        'collections_per_user_max' => 100,
        'page_size_max' => 50,
    ],

    // Contract "Rate limits" row and API-LINK-009 ("1/min per link"). "refresh-preview
    // 5/min" is provisionally a per-user limit on metadata refresh (DEC-003).
    'rate_limits' => [
        'api_per_minute' => 120,
        'login_per_minute' => 5,
        'register_per_hour' => 10,
        'forgot_password_per_hour' => 3,
        'link_preview_per_minute' => 20,
        'link_save_per_minute' => 60,
        'export_per_ten_minutes' => 1,
        'metadata_refresh_per_link_per_minute' => 1,
        'metadata_refresh_per_minute' => 5,
    ],

    // Sanctum bearer tokens are valid for 60 days; refresh issues a new 60-day token.
    'token_ttl_days' => 60,

    // POST /links Idempotency-Key: the first response is replayed for 24 hours.
    // Concurrent requests with the same key wait up to lock_wait_seconds (DEC-005).
    'idempotency' => [
        'ttl_hours' => 24,
        'lock_wait_seconds' => 5,
    ],

    // GET /config is cacheable for 5 minutes.
    'config_cache_seconds' => 300,

];
