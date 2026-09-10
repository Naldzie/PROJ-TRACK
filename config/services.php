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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),
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

    'gemini' => [
        'key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL', 'gemini-1.5-flash'),
        // E3 (2026-09-10): document/Excel parsing rides its own key + model
        // (institute second key; pinned gemini-2.5-flash per user). Fallbacks
        // keep everything working when the parser env is missing; prediction
        // services keep reading key/model above (Lite stays).
        'parser_key' => env('AI_PARSER_API_KEY', env('AI_API_KEY')),
        'parser_model' => env('AI_PARSER_MODEL', env('AI_MODEL', 'gemini-1.5-flash')),
    ],

];
