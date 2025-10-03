<?php

namespace App\Services;

use App\Models\User;
use App\Models\ChannelIdentity;
use Illuminate\Support\Facades\Log;

class EVMChannelService
{
    /**
     * Link EVM wallet (signature already verified on frontend)
     */
    public function linkWallet(
        string $ownerAddress,
        string $evmAddress,
        int $chainId
    ): ChannelIdentity {
        Log::info('=== EVM Channel Service: Link Wallet ===', [
            'owner_address' => $ownerAddress,
            'evm_address' => $evmAddress,
            'chain_id' => $chainId,
        ]);

        // Find or create user
        $user = User::firstOrCreate(
            ['owner_address' => $ownerAddress]
        );

        Log::info('User found/created', [
            'user_id' => $user->id,
            'has_primary_vault' => !is_null($user->primary_vault_address),
        ]);

        // Get chain name from chain ID
        $chainName = $this->getChainName($chainId);

        // Normalize EVM address to lowercase
        $normalizedAddress = strtolower($evmAddress);

        // Check if this EVM address is already linked to a different user
        $existingIdentity = ChannelIdentity::where('channel', 'evm')
            ->where('channel_user_id', $normalizedAddress)
            ->first();

        if ($existingIdentity && $existingIdentity->user_id !== $user->id) {
            throw new \Exception('This EVM address is already linked to another account');
        }

        // Create or update channel identity
        $identity = ChannelIdentity::updateOrCreate(
            [
                'user_id' => $user->id,
                'channel' => 'evm',
                'channel_user_id' => $normalizedAddress,
            ],
            [
                'vault_status' => 0, // Pending until blockchain transaction completes
                'metadata' => [
                    'address' => $evmAddress, // Keep original case for display
                    'chain_id' => $chainId,
                    'chain_name' => $chainName,
                ],
                'credentials' => null, // No OAuth tokens needed for EVM
            ]
        );

        Log::info('Channel identity created/updated', [
            'identity_id' => $identity->id,
            'channel' => $identity->channel,
            'channel_user_id' => $identity->channel_user_id,
        ]);

        return $identity;
    }

    /**
     * Get human-readable chain name from chain ID
     */
    private function getChainName(int $chainId): string
    {
        return match($chainId) {
            1 => 'Ethereum Mainnet',
            137 => 'Polygon',
            42161 => 'Arbitrum One',
            8453 => 'Base',
            10 => 'Optimism',
            56 => 'BNB Smart Chain',
            43114 => 'Avalanche C-Chain',
            250 => 'Fantom Opera',
            100 => 'Gnosis',
            5 => 'Goerli Testnet',
            11155111 => 'Sepolia Testnet',
            80001 => 'Mumbai Testnet',
            default => "Chain ID {$chainId}",
        };
    }
}