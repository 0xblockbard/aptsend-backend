<?php

namespace App\Services;

use App\Models\User;
use App\Models\ChannelIdentity;

interface ChannelInterface
{
    /**
     * Generate OAuth authorization URL
     */
    public function generateAuthUrl(string $ownerAddress, string $codeChallenge): array;

    /**
     * Handle OAuth callback and store credentials
     */
    public function handleCallback(string $code, string $state, string $codeVerifier): ChannelIdentity;

    /**
     * Refresh expired access token
     */
    public function refreshToken(ChannelIdentity $identity): void;

    /**
     * Revoke access and delete credentials
     */
    public function revokeAccess(ChannelIdentity $identity): bool;

    /**
     * Get channel name
     */
    public function getChannelName(): string;
}