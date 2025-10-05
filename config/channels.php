<?php

return [
    'twitter' => [
        'enabled' => env('TWITTER_ENABLED', true),
        'client_id' => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'bearer_token' => env('TWITTER_BEARER_TOKEN'),
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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'api_base_url' => 'https://www.googleapis.com/oauth2/v2',
        'scopes' => [
            'openid',
            'email',
            'profile',
        ],
    ],

    'discord' => [
        'enabled' => env('DISCORD_ENABLED', true),
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect_uri' => env('DISCORD_REDIRECT_URI'),
        'auth_url' => 'https://discord.com/api/oauth2/authorize',
        'token_url' => 'https://discord.com/api/oauth2/token',
        'api_base_url' => 'https://discord.com/api/v10',
        'scopes' => [
            'identify',
            // 'email', // Add if you need email
            // 'guilds', // Add if you need server list
        ],
    ],

    // Add more channels here later
    'telegram' => [
        'enabled' => env('TELEGRAM_ENABLED', false),
        // ...
    ],

];