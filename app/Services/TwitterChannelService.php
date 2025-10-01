<?php

namespace App\Services;

use App\Models\User;
use App\Models\ChannelIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TwitterChannelService implements ChannelInterface
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('channels.twitter');
    }

    public function getChannelName(): string
    {
        return 'twitter';
    }

    public function generateAuthUrl(string $ownerAddress, string $codeChallenge): array
    {
        $state = Str::random(40);
        
        // Store state with owner address
        Cache::put("twitter_oauth_state:{$state}", [
            'owner_address' => $ownerAddress,
            'code_challenge' => $codeChallenge,
        ], now()->addMinutes(10));

        $params = [
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => implode(' ', $this->config['scopes']),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        // DEBUG: Log everything
        \Log::info('Twitter OAuth Request', [
            'params' => $params,
            'config' => [
                'client_id' => $this->config['client_id'],
                'redirect_uri' => $this->config['redirect_uri'],
                'scopes' => $this->config['scopes'],
            ]
        ]);

        $authUrl = $this->config['auth_url'] . '?' . http_build_query($params);
        
        \Log::info('Generated Auth URL', ['url' => $authUrl]);

        return [
            'auth_url' => $authUrl,
            'state' => $state,
        ];
    }


    public function handleCallback(string $code, string $state, string $codeVerifier): ChannelIdentity
    {
        \Log::info('=== TWITTER SERVICE HANDLE CALLBACK ===');
        \Log::info('Input:', ['code' => $code, 'state' => $state, 'code_verifier' => $codeVerifier]);
        
        // Retrieve and validate state
        $cacheKey = "twitter_oauth_state:{$state}";
        \Log::info('Looking for cache key:', ['key' => $cacheKey]);
        
        $stateData = Cache::get($cacheKey);
        \Log::info('Cache data:', ['data' => $stateData]);

        \Log::info('All cache keys:', ['keys' => Cache::get('laravel_cache:twitter_oauth_state:*')]);
        
        if (!$stateData) {
            \Log::error('State not found in cache');
            throw new \Exception('Invalid or expired state parameter');
        }

        // Delete the state to prevent reuse
        Cache::forget($cacheKey);

        $ownerAddress = $stateData['owner_address'];
        $codeChallenge = $stateData['code_challenge'];
        
        \Log::info('Retrieved from cache:', [
            'owner_address' => $ownerAddress,
            'code_challenge' => $codeChallenge
        ]);

        // Exchange code for tokens
        \Log::info('Exchanging code for tokens...');
        
        $tokenResponse = Http::asForm()
            ->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
            ->post($this->config['token_url'], [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->config['redirect_uri'],
                'code_verifier' => $codeVerifier,
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

        // Get user info from Twitter
        \Log::info('Fetching Twitter user info...');
        
        $userResponse = Http::withToken($tokens['access_token'])
            ->get($this->config['api_base_url'] . '/users/me', [
                'user.fields' => 'id,username,name,profile_image_url'
            ]);

        if (!$userResponse->successful()) {
            \Log::error('Failed to fetch user info:', [
                'status' => $userResponse->status(),
                'body' => $userResponse->body()
            ]);
            throw new \Exception('Failed to fetch Twitter user info');
        }

        $twitterUser = $userResponse->json()['data'];
        \Log::info('Twitter user fetched:', ['username' => $twitterUser['username']]);

        // Find or create user
        $user = User::firstOrCreate(
            ['owner_address' => $ownerAddress]
        );
        
        \Log::info('User found/created:', ['user_id' => $user->id]);

        // Create or update channel identity
        $identity = ChannelIdentity::updateOrCreate(
            [
                'user_id' => $user->id,
                'channel' => 'twitter',
                'channel_user_id' => $twitterUser['id'],
            ],
            [
                'credentials' => [
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? null,
                ],
                'token_expires_at' => isset($tokens['expires_in']) 
                    ? now()->addSeconds($tokens['expires_in']) 
                    : null,
                'metadata' => [
                    'username' => $twitterUser['username'],
                    'name' => $twitterUser['name'],
                    'profile_image_url' => $twitterUser['profile_image_url'] ?? null,
                ],
                'vault_status' => 0, // temporary vault
            ]
        );

        \Log::info('Channel identity created:', ['id' => $identity->id]);

        return $identity;
    }

    protected function exchangeCodeForTokens(string $code, string $codeVerifier): array
    {
        $response = Http::asForm()->post($this->config['token_url'], [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'code_verifier' => $codeVerifier,
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to exchange code for tokens: ' . $response->body());
        }

        return $response->json();
    }

    protected function getUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get($this->config['api_base_url'] . '/users/me', [
                'user.fields' => 'id,name,username,profile_image_url',
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch user info: ' . $response->body());
        }

        return $response->json()['data'];
    }

    public function refreshToken(ChannelIdentity $identity): void
    {
        $refreshToken = $identity->credentials['refresh_token'] ?? null;

        if (!$refreshToken) {
            throw new \Exception('No refresh token available');
        }

        $response = Http::asForm()->post($this->config['token_url'], [
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'client_id' => $this->config['client_id'],
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to refresh token: ' . $response->body());
        }

        $tokens = $response->json();

        $identity->update([
            'credentials' => [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $refreshToken,
                'token_type' => $tokens['token_type'] ?? 'bearer',
            ],
            'token_expires_at' => now()->addSeconds($tokens['expires_in'] ?? 7200),
        ]);
    }

    public function revokeAccess(ChannelIdentity $identity): bool
    {
        try {
            $accessToken = $identity->credentials['access_token'] ?? null;

            if ($accessToken) {
                // Twitter's token revocation endpoint
                Http::asForm()
                    ->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
                    ->post($this->config['token_url'] . '/revoke', [
                        'token' => $accessToken,
                        'token_type_hint' => 'access_token',
                    ]);
            }

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to revoke Twitter token: ' . $e->getMessage());
            return false;
        }
    }
}