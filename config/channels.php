<?php

return [
    'twitter' => [
        'enabled' => env('TWITTER_ENABLED', true),
        'client_id' => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect_uri' => env('TWITTER_REDIRECT_URI'),
        'scopes' => [
            'tweet.read',
            'users.read',
            'offline.access', // For refresh tokens
        ],
        'auth_url' => 'https://twitter.com/i/oauth2/authorize',
        'token_url' => 'https://api.twitter.com/2/oauth2/token',
        'api_base_url' => 'https://api.twitter.com/2',
    ],

    // Add more channels here later
    'telegram' => [
        'enabled' => env('TELEGRAM_ENABLED', false),
        // ...
    ],

    'google' => [
        'enabled' => env('GOOGLE_ENABLED', false),
        // ...
    ],
];