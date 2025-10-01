<?php

// return [
//     'paths' => ['api/*'],
//     'allowed_methods' => ['*'],
//     'allowed_origins' => ['http://localhost:5174', 'http://127.0.0.1:5174'],
//     'allowed_origins_patterns' => [],
//     'allowed_headers' => ['*'],
//     'exposed_headers' => [],
//     'max_age' => 0,
//     'supports_credentials' => false,
// ];

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'auth/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:5174',
        'https://aptsend-backend.test',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];