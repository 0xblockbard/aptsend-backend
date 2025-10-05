<?php

namespace App\Services;

use App\Models\User;
use App\Models\ChannelIdentity;

class TelegramChannelService
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('channels.telegram');
    }

    public function getChannelName(): string
    {
        return 'telegram';
    }

    /**
     * Handle Telegram authentication callback
     * 
     * @param string $ownerAddress The wallet address
     * @param array $authData Auth data from Telegram widget
     * @return ChannelIdentity
     * @throws \Exception
     */
    public function handleCallback(string $ownerAddress, array $authData): ChannelIdentity
    {
        \Log::info('=== TELEGRAM SERVICE HANDLE CALLBACK ===');
        \Log::info('Input:', [
            'owner_address' => $ownerAddress,
            'telegram_id' => $authData['id'] ?? null,
            'username' => $authData['username'] ?? null
        ]);

        // Verify hash - critical security check
        $this->verifyTelegramHash($authData);
        \Log::info('Hash verification successful');

        // Check timestamp to prevent replay attacks
        $authDate = $authData['auth_date'];
        $ageInSeconds = time() - $authDate;

        if ($ageInSeconds > 86400) { // 24 hours
            \Log::error('Auth data too old', ['age_seconds' => $ageInSeconds]);
            throw new \Exception('Authentication data expired. Please try again.');
        }
        \Log::info('Timestamp check passed', ['age_seconds' => $ageInSeconds]);

        // Find or create user
        $user = User::firstOrCreate(
            ['owner_address' => $ownerAddress]
        );
        
        \Log::info('User found/created:', ['user_id' => $user->id]);

        // Check if this Telegram account is already linked to another user/wallet
        $existingIdentity = ChannelIdentity::where('channel', 'telegram')
            ->where('channel_user_id', $authData['id'])
            ->first();

        if ($existingIdentity && $existingIdentity->user_id !== $user->id) {
            \Log::error('Telegram account already linked to different wallet', [
                'telegram_id' => $authData['id'],
                'existing_user_id' => $existingIdentity->user_id,
                'requested_user_id' => $user->id
            ]);
            throw new \Exception('This Telegram account is already linked to another wallet');
        }

        // Create or update channel identity
        $identity = ChannelIdentity::updateOrCreate(
            [
                'user_id' => $user->id,
                'channel' => 'telegram',
                'channel_user_id' => $authData['id'],
            ],
            [
                'credentials' => null, // Telegram doesn't provide tokens
                'token_expires_at' => null,
                'metadata' => [
                    'username' => $authData['username'] ?? null,
                    'first_name' => $authData['first_name'] ?? null,
                    'last_name' => $authData['last_name'] ?? null,
                    'photo_url' => $authData['photo_url'] ?? null,
                ],
                'vault_status' => 0, // temporary vault
            ]
        );

        \Log::info('Channel identity created/updated:', [
            'id' => $identity->id,
            'telegram_id' => $identity->channel_user_id
        ]);

        return $identity;
    }

    /**
     * Verify Telegram authentication hash
     * 
     * This is the critical security check that proves the data
     * actually came from Telegram and wasn't forged by an attacker
     * 
     * @param array $authData
     * @throws \Exception if hash verification fails
     */
    protected function verifyTelegramHash(array $authData): void
    {
        \Log::info('Verifying Telegram hash...');

        // Extract the hash that Telegram sent
        $checkHash = $authData['hash'] ?? null;
        
        if (!$checkHash) {
            throw new \Exception('Missing hash in authentication data');
        }

        // Remove hash from data before verification
        unset($authData['hash']);
        
        // Build data check string (alphabetically sorted key=value pairs)
        $dataCheckArr = [];
        foreach ($authData as $key => $value) {
            $dataCheckArr[] = $key . '=' . $value;
        }
        sort($dataCheckArr);
        $dataCheckString = implode("\n", $dataCheckArr);
        
        \Log::info('Data check string built', [
            'length' => strlen($dataCheckString),
            'fields' => count($dataCheckArr)
        ]);
        
        // Get bot token from config
        $botToken = $this->config['bot_token'] ?? config('services.telegram.bot_token');
        
        if (!$botToken) {
            \Log::error('Telegram bot token not configured');
            throw new \Exception('Telegram bot token not configured');
        }
        
        // Calculate expected hash using bot token
        $secretKey = hash('sha256', $botToken, true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
        
        \Log::info('Hash calculation complete', [
            'calculated_hash' => substr($calculatedHash, 0, 10) . '...',
            'received_hash' => substr($checkHash, 0, 10) . '...'
        ]);
        
        // Compare hashes (timing-safe comparison)
        if (!hash_equals($calculatedHash, $checkHash)) {
            \Log::error('Hash verification failed - possible forgery attempt', [
                'telegram_id' => $authData['id'] ?? 'unknown'
            ]);
            throw new \Exception('Authentication verification failed. Data may be forged.');
        }

        \Log::info('Hash verified successfully');
    }

    /**
     * Unsync a Telegram account
     * 
     * @param string $ownerAddress
     * @param string $accountId
     * @throws \Exception
     */
    public function unsyncAccount(string $ownerAddress, string $accountId): void
    {
        \Log::info('Unsyncing Telegram account', [
            'owner_address' => $ownerAddress,
            'account_id' => $accountId
        ]);

        // Find the user
        $user = User::where('owner_address', $ownerAddress)->first();

        if (!$user) {
            \Log::error('User not found', ['owner_address' => $ownerAddress]);
            throw new \Exception('User not found');
        }

        // Find and delete the identity
        $identity = ChannelIdentity::where('id', $accountId)
            ->where('user_id', $user->id)
            ->where('channel', 'telegram')
            ->first();

        if (!$identity) {
            \Log::error('Telegram identity not found', [
                'account_id' => $accountId,
                'user_id' => $user->id
            ]);
            throw new \Exception('Telegram account not found or does not belong to this wallet');
        }

        $telegramId = $identity->channel_user_id;
        $identity->delete();

        \Log::info('Telegram account unsynced successfully', [
            'telegram_id' => $telegramId,
            'identity_id' => $accountId
        ]);
    }

    /**
     * Refresh token - Not applicable for Telegram
     * Telegram doesn't use OAuth tokens
     */
    public function refreshToken(ChannelIdentity $identity): void
    {
        // Telegram doesn't have refresh tokens
        // This method exists to satisfy the interface
        \Log::info('refreshToken called for Telegram (no-op)');
    }

    /**
     * Revoke access - Not applicable for Telegram
     * Telegram authentication is stateless
     */
    public function revokeAccess(ChannelIdentity $identity): bool
    {
        // Telegram doesn't have token revocation
        // Just delete the identity record
        \Log::info('revokeAccess called for Telegram', [
            'identity_id' => $identity->id
        ]);
        
        return true;
    }
}