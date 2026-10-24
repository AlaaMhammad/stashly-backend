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

    // DEC-021: until final legal copy exists, the backend serves draft pages at /terms and
    // /privacy. Empty environment values count as "not set" (.env.example ships them empty),
    // so the draft version applies; the URLs fall back to those routes in SystemController.
    'terms' => [
        'version' => env('STASHLY_TERMS_VERSION') ?: 'draft-1',
        'url' => env('STASHLY_TERMS_URL') ?: null,
    ],

    'privacy' => [
        'url' => env('STASHLY_PRIVACY_URL') ?: null,
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
        // POST /auth/google (DEC-035): the e-mail is unknown before the token is verified, so per IP.
        'google_auth_per_minute' => 10,
        // POST /links/{link}/summarize and POST /ai/ask (DEC-038): per user; keeps the free Gemini quota usable.
        'ai_per_minute' => 10,
        'metadata_refresh_per_link_per_minute' => 1,
        'metadata_refresh_per_minute' => 5,
    ],

    // POST /ai/ask sends at most this many of the user's most recent links to the model (DEC-038).
    'ai' => [
        'ask_max_links' => 30,
    ],

    // Sanctum bearer tokens are valid for 60 days; refresh issues a new 60-day token.
    'token_ttl_days' => 60,

    // POST /links Idempotency-Key: the first response is replayed for 24 hours.
    // Concurrent requests with the same key wait up to lock_wait_seconds (DEC-005).
    'idempotency' => [
        'ttl_hours' => 24,
        'lock_wait_seconds' => 5,
    ],

    // Metadata lookup (contract 4.2, security/ssrf.md).
    'metadata' => [
        'timeout_seconds' => 8,
        'preview_timeout_seconds' => 4,
        'max_bytes' => 512 * 1024,
        'max_redirects' => 3,
        // Delay before attempts 2, 3 and 4; temporary errors only (429, 5xx, timeout).
        'retry_delays_seconds' => [120, 900, 7200],
        'max_attempts' => 4,
        // DEC-006: still pending this long after the request or scheduled retry -> failed.
        'stale_after_minutes' => 15,
        'user_agent' => 'Mozilla/5.0 (compatible; StashlyBot/1.0; link preview)',
    ],

    // GET /config is cacheable for 5 minutes.
    'config_cache_seconds' => 300,

];
