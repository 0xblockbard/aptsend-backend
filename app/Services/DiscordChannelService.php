<?php

namespace App\Services;

use App\Models\User;
use App\Models\ChannelIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DiscordChannelService
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('channels.discord');
    }

    public function getChannelName(): string
    {
        return 'discord';
    }

    public function generateAuthUrl(string $ownerAddress): array
    {
        $state = Str::random(40);
        
        // Store state with owner address
        Cache::put("discord_oauth_state:{$state}", [
            'owner_address' => $ownerAddress,
        ], now()->addMinutes(10));

        $params = [
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', $this->config['scopes']),
            'state' => $state,
        ];

        \Log::info('Discord OAuth Request', [
            'params' => $params,
            'config' => [
                'client_id' => $this->config['client_id'],
                'redirect_uri' => $this->config['redirect_uri'],
                'scopes' => $this->config['scopes'],
            ]
        ]);

        $authUrl = $this->config['auth_url'] . '?' . http_build_query($params);
        
        \Log::info('Generated Discord Auth URL', ['url' => $authUrl]);

        return [
            'auth_url' => $authUrl,
            'state' => $state,
        ];
    }

    public function handleCallback(string $code, string $state): ChannelIdentity
    {
        \Log::info('=== DISCORD SERVICE HANDLE CALLBACK ===');
        \Log::info('Input:', ['code' => $code, 'state' => $state]);
        
        // Retrieve and validate state
        $cacheKey = "discord_oauth_state:{$state}";
        \Log::info('Looking for cache key:', ['key' => $cacheKey]);
        
        $stateData = Cache::get($cacheKey);
        \Log::info('Cache data:', ['data' => $stateData]);
        
        if (!$stateData) {
            \Log::error('State not found in cache');
            throw new \Exception('Invalid or expired state parameter');
        }

        // Delete the state to prevent reuse
        Cache::forget($cacheKey);

        $ownerAddress = $stateData['owner_address'];
        
        \Log::info('Retrieved from cache:', [
            'owner_address' => $ownerAddress
        ]);

        // Exchange code for tokens
        \Log::info('Exchanging code for tokens...');
        
        $tokenResponse = Http::asForm()
            ->post($this->config['token_url'], [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config['redirect_uri'],
            ]);

        \Log::info('Token response status:', ['status' => $tokenResponse->status()]);

        if (!$tokenResponse->successful()) {
            \Log::error('Token exchange failed:', [
                'status' => $tokenResponse->status(),
                'body' => $tokenResponse->body()
            ]);
            throw new \Exception('Failed to exchange code for tokens: ' . $tokenResponse->body());
        }

        $tokens = $tokenResponse->json();
        \Log::info('Tokens received');

        // Get user info from Discord
        \Log::info('Fetching Discord user info...');
        
        $userResponse = Http::withToken($tokens['access_token'])
            ->get($this->config['api_base_url'] . '/users/@me');

        if (!$userResponse->successful()) {
            \Log::error('Failed to fetch user info:', [
                'status' => $userResponse->status(),
                'body' => $userResponse->body()
            ]);
            throw new \Exception('Failed to fetch Discord user info');
        }

        $discordUser = $userResponse->json();
        \Log::info('Discord user fetched:', [
            'id' => $discordUser['id'],
            'username' => $discordUser['username']
        ]);

        // Find or create user
        $user = User::firstOrCreate(
            ['owner_address' => $ownerAddress]
        );
        
        \Log::info('User found/created:', ['user_id' => $user->id]);

        // Check if identity is already linked to another user/wallet
        $existingIdentity = ChannelIdentity::where('channel', 'discord')
            ->where('channel_user_id', $discordUser['id'])
            ->first();

        if ($existingIdentity && $existingIdentity->user_id !== $user->id) {
            throw new \Exception('This Discord account is already linked to another wallet');
        }

        // Create or update channel identity
        $identity = ChannelIdentity::updateOrCreate(
            [
                'user_id' => $user->id,
                'channel' => 'discord',
                'channel_user_id' => $discordUser['id'],
            ],
            [
                'credentials' => [
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? null,
                    'token_type' => $tokens['token_type'] ?? 'Bearer',
                ],
                'token_expires_at' => isset($tokens['expires_in']) 
                    ? now()->addSeconds($tokens['expires_in']) 
                    : null,
                'metadata' => [
                    'username' => $discordUser['username'],
                    'discriminator' => $discordUser['discriminator'] ?? null,
                    'avatar' => $discordUser['avatar'] ?? null,
                    'global_name' => $discordUser['global_name'] ?? null,
                ],
                'vault_status' => 0, // temporary vault
            ]
        );

        \Log::info('Channel identity created:', ['id' => $identity->id]);

        return $identity;
    }

    public function refreshToken(ChannelIdentity $identity): void
    {
        $refreshToken = $identity->credentials['refresh_token'] ?? null;

        if (!$refreshToken) {
            throw new \Exception('No refresh token available');
        }

        $response = Http::asForm()->post($this->config['token_url'], [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to refresh token: ' . $response->body());
        }

        $tokens = $response->json();

        $identity->update([
            'credentials' => [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $refreshToken,
                'token_type' => $tokens['token_type'] ?? 'Bearer',
            ],
            'token_expires_at' => now()->addSeconds($tokens['expires_in'] ?? 604800), // Discord default: 7 days
        ]);
    }

    public function revokeAccess(ChannelIdentity $identity): bool
    {
        try {
            $accessToken = $identity->credentials['access_token'] ?? null;

            if ($accessToken) {
                // Discord's token revocation endpoint
                Http::asForm()
                    ->post($this->config['token_url'] . '/revoke', [
                        'client_id' => $this->config['client_id'],
                        'client_secret' => $this->config['client_secret'],
                        'token' => $accessToken,
                    ]);
            }

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to revoke Discord token: ' . $e->getMessage());
            return false;
        }
    }
}