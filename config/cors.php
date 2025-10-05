<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'auth/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => env('APP_ENV') === 'production' 
        ? [
            'https://aptsend.com', // Only production domain
        ]
        : [
            'http://localhost:5174',
            'https://aptsend-backend.test',
            'https://rica-exciting-ghz-heath.trycloudflare.com'
        ],
    'allowed_origins_patterns' => env('APP_ENV') === 'production'
        ? [] // No patterns in production
        : [
            '/^https:\/\/.*\.trycloudflare\.com$/', // Dev only
        ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];