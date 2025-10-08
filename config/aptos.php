<?php

return [
    'module_address' => env('APTOS_MODULE_ADDRESS', ''),
    'node_url' => env('APTOS_NODE_URL', 'https://fullnode.mainnet.aptoslabs.com/v1'),
    'node_command' => env('APTOS_NODE_COMMAND', 'node'),
    'min_transfer_amount' => env('APTOS_MIN_TRANSFER_AMOUNT', 1000), // 0.00001 APT in Octas
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