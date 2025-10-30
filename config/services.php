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

    'google' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'allowed_audiences' => array_values(array_filter(array_map(
            static fn ($value) => trim($value),
            explode(',', (string) env('GOOGLE_ALLOWED_AUDIENCES', (string) env('GOOGLE_OAUTH_CLIENT_ID', '')))
        ))),
        'token_ttl' => (int) env('GOOGLE_TOKEN_TTL', (int) env('OIDC_TOKEN_TTL', 3600)),
    ],

    'apple' => [
        'client_id' => env('APPLE_OAUTH_CLIENT_ID'),
        'client_ids' => array_values(array_filter(array_map(
            static fn ($value) => trim($value),
            explode(',', (string) env('APPLE_OAUTH_CLIENT_IDS', (string) env('APPLE_OAUTH_CLIENT_ID', '')))
        ))),
        'issuer' => env('APPLE_OAUTH_ISSUER', 'https://appleid.apple.com'),
        'discovery' => env('APPLE_OIDC_DISCOVERY', 'https://appleid.apple.com/.well-known/openid-configuration'),
        'allowed_algs' => array_values(array_filter(array_map(
            static fn ($value) => strtoupper(trim($value)),
            explode(',', (string) env('APPLE_ALLOWED_ALGS', 'RS256'))
        ))),
        'leeway' => (int) env('APPLE_OIDC_LEEWAY', 120),
        'cache_ttl' => env('APPLE_OIDC_CACHE_TTL'),
        'token_ttl' => (int) env('APPLE_TOKEN_TTL', (int) env('OIDC_TOKEN_TTL', 3600)),
    ],

];
