<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DiscordChannelService;
use App\Jobs\RegisterUserJob;
use App\Jobs\SyncUserJob;
use Illuminate\Http\Request;

class DiscordAuthController extends Controller
{
    public function __construct(
        protected DiscordChannelService $discordService
    ) {}

    /**
     * Generate Discord OAuth URL
     */
    public function getAuthUrl(Request $request)
    {
        \Log::info('=== DISCORD GET AUTH URL STARTED ===');
        \Log::info('Request data:', $request->all());

        $request->validate([
            'owner_address' => 'required|string',
        ]);

        try {
            $data = $this->discordService->generateAuthUrl(
                $request->owner_address
            );

            \Log::info('Discord auth URL generated successfully');
            \Log::info('Response:', $data);

            return response()->json($data);
        } catch (\Exception $e) {
            \Log::error('Failed to generate Discord auth URL:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Handle Discord OAuth redirect (GET request from Discord)
     */
    public function handleOAuthRedirect(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        
        $frontendUrl = config('app.frontend_url', 'http://localhost:5174');
        
        if ($error) {
            return redirect("{$frontendUrl}/auth/discord/callback?error={$error}");
        }
        
        if (!$code || !$state) {
            return redirect("{$frontendUrl}/auth/discord/callback?error=missing_parameters");
        }
        
        // Redirect to frontend callback with code and state
        return redirect("{$frontendUrl}/auth/discord/callback?code={$code}&state={$state}");
    }

    /**
     * Handle Discord OAuth callback
     */
    public function handleCallback(Request $request)
    {
        \Log::info('=== DISCORD CALLBACK STARTED ===');
        \Log::info('Request data:', $request->all());

        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        try {
            $identity = $this->discordService->handleCallback(
                $request->code,
                $request->state
            );

            // Dispatch appropriate job based on user registration status
            if ($identity->user->primary_vault_address) {
                // User already has a vault, just sync this new channel
                \Log::info('Dispatching SyncUserJob for existing user', [
                    'user_id' => $identity->user_id,
                    'identity_id' => $identity->id,
                ]);
                SyncUserJob::dispatch($identity);
            } else {
                // New user, needs full registration
                \Log::info('Dispatching RegisterUserJob for new user', [
                    'user_id' => $identity->user_id,
                    'identity_id' => $identity->id,
                ]);
                RegisterUserJob::dispatch($identity->user, $identity);
            }

            return response()->json([
                'success' => true,
                'identity' => [
                    'id' => $identity->id,
                    'channel' => $identity->channel,
                    'channel_user_id' => $identity->channel_user_id,
                    'vault_status' => $identity->vault_status,
                    'metadata' => $identity->metadata,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Discord callback failed:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Unsync Discord account
     */
    public function unsync(Request $request)
    {
        $request->validate([
            'owner_address' => 'required|string',
            'account_id' => 'required|string',
        ]);

        try {
            $user = User::where('owner_address', $request->owner_address)->firstOrFail();
            
            $identity = $user->channelIdentities()
                ->where('channel', 'discord')
                ->where('id', $request->account_id)
                ->firstOrFail();

            // Optionally revoke Discord access
            $this->discordService->revokeAccess($identity);

            // Delete the identity
            $identity->delete();

            return response()->json([
                'success' => true,
                'message' => 'Discord account unsynced successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to unsync Discord account:', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}