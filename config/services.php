<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),

        'calendar_scopes' => [
            'openid',
            'email',
            'profile',
            'https://www.googleapis.com/auth/calendar.events',
        ],
    ],

    'supabase' => [
        'url' => env('SUPABASE_URL'),
        'key' => env('SUPABASE_ANON_KEY'),
        'redirect' => env('SUPABASE_REDIRECT_URI'),
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
        'parser_key' => env('AI_PARSER_API_KEY', env('AI_API_KEY')),
        'parser_model' => env('AI_PARSER_MODEL', env('AI_MODEL', 'gemini-1.5-flash')),
        'parser_fallback_models' => env('AI_PARSER_FALLBACK_MODELS', ''),
        'parser_time_budget' => env('AI_PARSER_TIME_BUDGET', 50),
        'parser_image_timeout' => env('AI_PARSER_IMAGE_TIMEOUT', 15),
        'parser_text_timeout' => env('AI_PARSER_TEXT_TIMEOUT', 30),
    ],

];
