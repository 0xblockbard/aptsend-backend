<?php

return [
    'module_address' => env('APTOS_MODULE_ADDRESS', ''),
    'node_url' => env('APTOS_NODE_URL', 'https://fullnode.mainnet.aptoslabs.com/v1'),
    'supported_fas' => [
        [
            'symbol' => 'USDC',
            'metadata' => env('APTOS_USDC_METADATA', ''),
            'decimals' => 6
        ],
        [
            'symbol' => 'USDT',
            'metadata' => env('APTOS_USDT_METADATA', ''),
            'decimals' => 6
        ],
    ],
];