<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class CheckerController extends Controller
{
    public function getIdentity(Request $request)
    {
        $request->validate([
            'channel' => 'required|in:twitter,telegram,email,discord',
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
            case 'email':
                return strtolower(trim($identifier));
            case 'discord':
                return $this->resolveDiscord($identifier);
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
            $bearerToken = config('services.twitter.bearer_token');
            
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
        if (is_numeric($identifier)) {
            return $identifier;
        }
        throw new \Exception("Telegram username resolution not yet implemented");
    }

    private function resolveDiscord(string $identifier): string
    {
        if (is_numeric($identifier)) {
            return $identifier;
        }
        throw new \Exception("Discord username resolution not yet implemented");
    }
}