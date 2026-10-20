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

    // Contract "Rate limits" row. "refresh-preview 5/min" is pending DEC-003.
    'rate_limits' => [
        'api_per_minute' => 120,
        'login_per_minute' => 5,
        'register_per_hour' => 10,
        'forgot_password_per_hour' => 3,
        'link_preview_per_minute' => 20,
        'link_save_per_minute' => 60,
        'export_per_ten_minutes' => 1,
    ],

    // Sanctum bearer tokens are valid for 60 days; refresh issues a new 60-day token.
    'token_ttl_days' => 60,

    // GET /config is cacheable for 5 minutes.
    'config_cache_seconds' => 300,

];
