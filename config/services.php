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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // The notification bot. TELEGRAM_WEBHOOK_SECRET is a shared secret
    // Telegram sends back on every update, which is what makes the public
    // webhook endpoint safe to leave open.
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'bot' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    // Browser notifications. The key pair is this application's identity to
    // the push services; generate one with `php artisan webpush:keys`.
    // Changing it invalidates every existing subscription.
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // Where a push service can complain about this application.
        'subject' => env('VAPID_SUBJECT'),
    ],

    // Read-only, and only used by `php artisan jira:import`. The token is an
    // Atlassian API token belonging to JIRA_USER; it is a password, so it
    // lives in the environment and nowhere else.
    'jira' => [
        'url' => env('JIRA_URL'),
        'user' => env('JIRA_USER'),
        'token' => env('JIRA_TOKEN'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // Tried in order; the first that answers wins. Keep the fastest model
        // first — the assistant runs several round-trips per reply.
        'models' => array_values(array_filter(array_map('trim', explode(
            ',',
            env('GEMINI_MODELS', 'gemini-2.5-flash,gemini-flash-latest,gemini-2.5-pro')
        )))),
    ],

];
