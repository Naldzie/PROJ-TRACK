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

        // E4 (2026-09-13): a PARSER-SPECIFIC fallback ladder, comma-separated, tried
        // in order when the parser model fails a transport check (per-model 429,
        // 503, dead id). It is deliberately NOT backupModel(): that resolves to
        // AI_MODEL, which is Lite, and Lite is excluded from the S-curve paths
        // because it copies author summaries instead of computing (E3 verdict).
        // Gemini meters are per MODEL, so a version ladder is real headroom.
        // Empty by default: with nothing configured every path behaves exactly as
        // it did before, including the PDF path's Flash-or-nothing rule.
        'parser_fallback_models' => env('AI_PARSER_FALLBACK_MODELS', ''),

        // E5 (2026-09-13): wall-clock seconds the whole AI call may spend, across
        // the primary's attempts AND every fallback rung. PHP kills the request at
        // max_execution_time (60s on the dev server) and a FATAL cannot be caught,
        // so the mapped "the AI service is busy" message never reaches the client
        // and the user is told the file could not be read instead. Measured live
        // 2026-09-13: a ladder walk took 74s and died mid-rung.
        // The effective budget is capped against PHP's own limit at runtime, so
        // this number only ever makes the window SMALLER, never unsafe.
        'parser_time_budget' => env('AI_PARSER_TIME_BUDGET', 50),
    ],

];
