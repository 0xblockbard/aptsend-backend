<?php

namespace App\Services;

use App\Models\User;
use App\Models\ChannelIdentity;
use Illuminate\Support\Facades\Log;

class SolanaChannelService
{
    /**
     * Link Solana wallet (signature already verified on frontend)
     */
    public function linkWallet(
        string $ownerAddress,
        string $solanaAddress
    ): ChannelIdentity {
        Log::info('=== Solana Channel Service: Link Wallet ===', [
            'owner_address' => $ownerAddress,
            'solana_address' => $solanaAddress,
        ]);

        // Find or create user
        $user = User::firstOrCreate(
            ['owner_address' => $ownerAddress]
        );

        Log::info('User found/created', [
            'user_id' => $user->id,
            'has_primary_vault' => !is_null($user->primary_vault_address),
        ]);

        // Check if this Solana address is already linked to a different user
        $existingIdentity = ChannelIdentity::where('channel', 'sol')
            ->where('channel_user_id', $solanaAddress)
            ->first();

        if ($existingIdentity && $existingIdentity->user_id !== $user->id) {
            throw new \Exception('This Solana address is already linked to another account');
        }

        // Create or update channel identity
        $identity = ChannelIdentity::updateOrCreate(
            [
                'user_id' => $user->id,
                'channel' => 'sol',
                'channel_user_id' => $solanaAddress,
            ],
            [
                'vault_status' => 0, // Pending until blockchain transaction completes
                'metadata' => [
                    'address' => $solanaAddress,
                ],
                'credentials' => null, // No OAuth tokens needed for Solana
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
     * Unlink Solana wallet
     */
    public function unlinkWallet(string $ownerAddress, string $identityId): void
    {
        Log::info('=== Solana Channel Service: Unlink Wallet ===', [
            'owner_address' => $ownerAddress,
            'identity_id' => $identityId,
        ]);

        // Find the user
        $user = User::where('owner_address', $ownerAddress)->firstOrFail();

        // Find the identity and verify it belongs to this user
        $identity = ChannelIdentity::where('id', $identityId)
            ->where('user_id', $user->id)
            ->where('channel', 'sol')
            ->firstOrFail();

        // Delete the identity
        $identity->delete();

        Log::info('Solana identity deleted', [
            'identity_id' => $identityId,
        ]);
    }
}