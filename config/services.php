<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('OPENROUTER_MODEL', 'openai/gpt-5.6-luna'),
        'reasoning' => env('OPENROUTER_REASONING', 'low'),
        'manual' => env('ASSISTANT_MANUAL_PATH', 'docs/MANUAL.md'),
    ],

    'elevenlabs' => [
        'key' => env('ELEVENLABS_API_KEY'),
        'url' => env('ELEVENLABS_URL', 'https://api.elevenlabs.io/v1'),
        'stt_model' => env('ELEVENLABS_STT_MODEL', 'scribe_v2'),
        'realtime_url' => env('ELEVENLABS_REALTIME_URL', 'wss://api.elevenlabs.io/v1/speech-to-text/realtime'),
        'realtime_model' => env('ELEVENLABS_REALTIME_MODEL', 'scribe_v2_realtime'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Where the Windows print program connects its websocket (the public Reverb address).
    'print_agent' => [
        'ws_host' => env('PRINT_AGENT_WS_HOST', env('VITE_REVERB_HOST')),
        'ws_port' => env('PRINT_AGENT_WS_PORT', env('VITE_REVERB_PORT')),
        'ws_scheme' => env('PRINT_AGENT_WS_SCHEME', env('VITE_REVERB_SCHEME')),
        // Where the deploy publishes the built .exe and its version.txt (default storage/app/private/print-agent).
        'release_path' => env('PRINT_AGENT_RELEASE_PATH'),
    ],

];
