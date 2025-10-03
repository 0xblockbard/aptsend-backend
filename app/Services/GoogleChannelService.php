<?php

namespace App\Services;

use App\Models\User;
use App\Models\ChannelIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GoogleChannelService implements ChannelInterface
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('channels.google');
    }

    public function getChannelName(): string
    {
        return 'google';
    }

    public function generateAuthUrl(string $ownerAddress, string $codeChallenge): array
    {
        $state = Str::random(40);
        
        Cache::put("google_oauth_state:{$state}", [
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
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        \Log::info('Google OAuth Request', [
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
        \Log::info('=== GOOGLE SERVICE HANDLE CALLBACK ===');
        \Log::info('Input:', ['code' => $code, 'state' => $state, 'code_verifier' => $codeVerifier]);
        
        $cacheKey = "google_oauth_state:{$state}";
        \Log::info('Looking for cache key:', ['key' => $cacheKey]);
        
        $stateData = Cache::get($cacheKey);
        \Log::info('Cache data:', ['data' => $stateData]);
        
        if (!$stateData) {
            \Log::error('State not found in cache');
            throw new \Exception('Invalid or expired state parameter');
        }

        Cache::forget($cacheKey);

        $ownerAddress = $stateData['owner_address'];
        $codeChallenge = $stateData['code_challenge'];
        
        \Log::info('Retrieved from cache:', [
            'owner_address' => $ownerAddress,
            'code_challenge' => $codeChallenge
        ]);

        \Log::info('Exchanging code for tokens...');
        
        $tokenResponse = Http::asForm()
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

        \Log::info('Fetching Google user info...');
        
        $userResponse = Http::withToken($tokens['access_token'])
            ->get($this->config['api_base_url'] . '/userinfo');

        \Log::info('Google userinfo response status:', ['status' => $userResponse->status()]);
        \Log::info('Google userinfo response body:', ['body' => $userResponse->json()]);

        if (!$userResponse->successful()) {
            \Log::error('Failed to fetch user info:', [
                'status' => $userResponse->status(),
                'body' => $userResponse->body()
            ]);
            throw new \Exception('Failed to fetch Google user info');
        }

        $googleUser = $userResponse->json();
        \Log::info('Google user fetched:', ['email' => $googleUser['email']]);

        $user = User::firstOrCreate(
            ['owner_address' => $ownerAddress]
        );
        
        \Log::info('User found/created:', ['user_id' => $user->id]);

        $identity = ChannelIdentity::updateOrCreate(
            [
                'user_id' => $user->id,
                'channel' => 'google',
                'channel_user_id' => $googleUser['email'],
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
                    'google_user_id' => $googleUser['id'],
                    'email' => $googleUser['email'],
                    'name' => $googleUser['name'] ?? null,
                    'picture' => $googleUser['picture'] ?? null,
                ],
                'vault_status' => 0,
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
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
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
            'token_expires_at' => now()->addSeconds($tokens['expires_in'] ?? 3600),
        ]);
    }

    public function revokeAccess(ChannelIdentity $identity): bool
    {
        try {
            $accessToken = $identity->credentials['access_token'] ?? null;

            if ($accessToken) {
                Http::post('https://oauth2.googleapis.com/revoke', [
                    'token' => $accessToken,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to revoke Google token: ' . $e->getMessage());
            return false;
        }
    }
}