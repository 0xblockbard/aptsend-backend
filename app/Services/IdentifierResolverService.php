<?php

namespace App\Services;

use App\Models\ChannelIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class IdentifierResolverService
{
    /**
     * Resolve an identifier to a channel user ID
     *
     * @param string $channel
     * @param string $identifier
     * @return string
     * @throws \Exception
     */
    public function resolve(string $channel, string $identifier): string
    {
        $identifier = trim($identifier);
        
        Log::info('Resolving identifier', [
            'channel' => $channel,
            'identifier' => $identifier
        ]);

        switch ($channel) {
            case 'twitter':
                return $this->resolveTwitter($identifier);
            case 'telegram':
                return $this->resolveTelegram($identifier);
            case 'google':
                return strtolower($identifier);
            case 'discord':
                return $this->resolveDiscord($identifier);
            case 'evm':
                return $this->resolveEvm(strtolower($identifier));
            case 'sol':
                return $this->resolveSol($identifier);
            default:
                throw new \Exception("Unsupported channel: {$channel}");
        }
    }

    /**
     * Resolve Twitter username to user ID
     */
    public function resolveTwitter(string $identifier): string
    {
        $handle = ltrim($identifier, '@');
        
        Log::info('Resolving Twitter handle', [
            'original' => $identifier,
            'cleaned' => $handle
        ]);
        
        $cacheKey = "twitter_user_id:{$handle}";

        return Cache::remember($cacheKey, 3600, function () use ($handle) {
            $bearerToken = config('channels.twitter.bearer_token');
            
            if (!$bearerToken) {
                throw new \Exception("Twitter bearer token not configured");
            }
            
            $url = "https://api.twitter.com/2/users/by/username/{$handle}";
            
            Log::info('Calling Twitter API', ['url' => $url]);
            
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$bearerToken}"
            ])->get($url);
            
            Log::info('Twitter API response', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);
            
            if (!$response->successful()) {
                throw new \Exception("Twitter user not found: {$handle}");
            }
            
            $data = $response->json();
            if (!isset($data['data']['id'])) {
                throw new \Exception("Twitter user not found: {$handle}");
            }
            
            return $data['data']['id'];
        });
    }

    /**
     * Resolve Telegram username to user ID
     */
    public function resolveTelegram(string $identifier): string
    {
        $username = ltrim($identifier, '@');
        
        Log::info('Resolving Telegram username from database', [
            'original' => $identifier,
            'cleaned' => $username
        ]);
        
        $cacheKey = "telegram_user_id:{$username}";

        return Cache::remember($cacheKey, 3600, function () use ($username) {
            $lowercaseUsername = strtolower($username);
            
            $identity = ChannelIdentity::byChannel('telegram')
                ->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.username"))) = ?', [$lowercaseUsername])
                ->first();
            
            if (!$identity) {
                throw new \Exception("Telegram username '{$username}' not found. User must register first.");
            }
            
            return $identity->channel_user_id;
        });
    }

    /**
     * Resolve Discord username to user ID
     */
    public function resolveDiscord(string $identifier): string
    {
        $username = ltrim($identifier, '@');
        
        Log::info('Resolving Discord username from database', [
            'original' => $identifier,
            'cleaned' => $username
        ]);
        
        $cacheKey = "discord_user_id:{$username}";

        return Cache::remember($cacheKey, 3600, function () use ($username) {
            $lowercaseUsername = strtolower($username);
            
            $identity = ChannelIdentity::byChannel('discord')
                ->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.username"))) = ?', [$lowercaseUsername])
                ->first();
            
            if (!$identity) {
                throw new \Exception("Discord username '{$username}' not found. User must register first.");
            }
            
            return $identity->channel_user_id;
        });
    }

    /**
     * Resolve EVM address
     */
    public function resolveEvm(string $identifier): string
    {
        Log::info('Resolving EVM address', ['identifier' => $identifier]);
        
        $cacheKey = "evm_user_id:{$identifier}";

        return Cache::remember($cacheKey, 3600, function () use ($identifier) {
            return $identifier;
        });
    }

    /**
     * Resolve Solana address
     */
    public function resolveSol(string $identifier): string
    {
        Log::info('Resolving SOL address', ['identifier' => $identifier]);
        
        $cacheKey = "sol_user_id:{$identifier}";

        return Cache::remember($cacheKey, 3600, function () use ($identifier) {
            return $identifier;
        });
    }
}