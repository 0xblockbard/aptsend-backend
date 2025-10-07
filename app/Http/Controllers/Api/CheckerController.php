<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\ChannelIdentity;

class CheckerController extends Controller
{
    public function getIdentity(Request $request)
    {
        $request->validate([
            'channel' => 'required|in:twitter,telegram,google,discord,evm,sol',
            'identifier' => 'required|string|max:255',
        ]);

        $channel = $request->query('channel');
        $identifier = trim($request->query('identifier'));

        // Add this logging
        \Log::info('Checker request received', [
            'channel' => $channel,
            'identifier' => $identifier,
            'raw_identifier' => $request->query('identifier')
        ]);

        try {
            $channelUserId = $this->resolveIdentifier($channel, $identifier);

            return response()->json([
                'success' => true,
                'channel' => $channel,
                'identifier' => $identifier,
                'channel_user_id' => $channelUserId
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    private function resolveIdentifier(string $channel, string $identifier): string
    {
        switch ($channel) {
            case 'twitter':
                return $this->resolveTwitter($identifier);
            case 'telegram':
                return $this->resolveTelegram($identifier);
            case 'google':
                return strtolower(trim($identifier));
            case 'discord':
                return $this->resolveDiscord($identifier);
            case 'evm':
                return $this->resolveEvm(strtolower(trim($identifier)));
            case 'sol':
                return $this->resolveSol($identifier);
            default:
                throw new \Exception("Unsupported channel: {$channel}");
        }
    }

    private function resolveTwitter(string $identifier): string
    {
        $handle = ltrim($identifier, '@');

         \Log::info('Resolving Twitter handle', [
            'original' => $identifier,
            'cleaned' => $handle
        ]);
        
        $cacheKey = "twitter_user_id:{$handle}";

        return Cache::remember($cacheKey, 3600, function () use ($handle) {
            $bearerToken = config('channels.twitter.bearer_token');
            
            $url = "https://api.twitter.com/2/users/by/username/{$handle}";
            
            \Log::info('Calling Twitter API', ['url' => $url]);
            
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$bearerToken}"
            ])->get($url);
            
            \Log::info('Twitter API response', [
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

    private function resolveTelegram(string $identifier): string
    {
        // Remove @ if present
        $username = ltrim($identifier, '@');
        
        \Log::info('Resolving Telegram username from database', [
            'original' => $identifier,
            'cleaned' => $username
        ]);
        
        $cacheKey = "telegram_user_id:{$username}";

        return Cache::remember($cacheKey, 3600, function () use ($username) {
            $lowercaseUsername = strtolower($username);
            
            // Search by unique username only
            $identity = ChannelIdentity::byChannel('telegram')
                ->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.username"))) = ?', [$lowercaseUsername])
                ->first();
            
            if (!$identity) {
                throw new \Exception("Telegram username '{$username}' not found. User must register their Telegram account first.");
            }
            
            return $identity->channel_user_id;
        });
    }

    private function resolveDiscord(string $identifier): string
    {
        $username = ltrim($identifier, '@');
        
        \Log::info('Resolving Discord username from database', [
            'original' => $identifier,
            'cleaned' => $username
        ]);
        
        $cacheKey = "discord_user_id:{$username}";

        return Cache::remember($cacheKey, 3600, function () use ($username) {
            $lowercaseUsername = strtolower($username);
            
            // Search by unique username only
            $identity = ChannelIdentity::byChannel('discord')
                ->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.username"))) = ?', [$lowercaseUsername])
                ->first();
            
            if (!$identity) {
                throw new \Exception("Discord username '{$username}' not found. User must register their Discord account first.");
            }
            
            return $identity->channel_user_id;
        });
    }

    private function resolveEvm(string $identifier): string
    {
         \Log::info('Resolving EVM handle', [
            'identifier' => $identifier
        ]);
        
        $cacheKey = "evm_user_id:{$identifier}";

        return Cache::remember($cacheKey, 3600, function () use ($identifier) {
            
            return $identifier;
        });
    }

    private function resolveSol(string $identifier): string
    {
         \Log::info('Resolving SOL handle', [
            'identifier' => $identifier
        ]);
        
        $cacheKey = "sol_user_id:{$identifier}";

        return Cache::remember($cacheKey, 3600, function () use ($identifier) {
            
            return $identifier;
        });
    }
}