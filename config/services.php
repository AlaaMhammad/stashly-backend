<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    // Google Sign-In (DEC-035). client_id is the OAuth 2.0 client ID that ID tokens must be issued
    // for (the "audience"); for Android with Credential Manager this is the *web* client ID passed
    // as serverClientId. Empty = feature disabled and GET /config reports auth.google_enabled=false.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID') ?: null,
        'jwks_url' => 'https://www.googleapis.com/oauth2/v3/certs',
    ],

    // On-demand AI (DEC-038): Google Gemini REST API, synchronous, no queue or cron. Empty key =
    // the AI endpoints answer 503 AI_SERVICE_UNAVAILABLE and GET /config reports ai.enabled=false.
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY') ?: null,
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 20),
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
