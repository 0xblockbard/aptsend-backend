<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleChannelService;
use App\Jobs\RegisterUserJob;
use App\Jobs\SyncUserJob;
use Illuminate\Http\Request;

class GoogleAuthController extends Controller
{
    public function __construct(
        protected GoogleChannelService $googleService
    ) {}

    /**
     * Generate Google OAuth URL
     */
    public function getAuthUrl(Request $request)
    {
        \Log::info('=== GET AUTH URL STARTED ===');
        \Log::info('Request data:', $request->all());

        $request->validate([
            'owner_address' => 'required|string',
            'code_challenge' => 'required|string',
        ]);

        try {
            $data = $this->googleService->generateAuthUrl(
                $request->owner_address,
                $request->code_challenge
            );

            \Log::info('Auth URL generated successfully');
            \Log::info('Response:', $data);

            return response()->json($data);
        } catch (\Exception $e) {
            \Log::error('Failed to generate auth URL:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Handle Google OAuth redirect (GET request from Google)
     */
    public function handleOAuthRedirect(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        
        $frontendUrl = config('app.frontend_url', 'http://localhost:5174');
        
        if ($error) {
            return redirect("{$frontendUrl}/auth/google/callback?error={$error}");
        }
        
        if (!$code || !$state) {
            return redirect("{$frontendUrl}/auth/google/callback?error=missing_parameters");
        }
        
        return redirect("{$frontendUrl}/auth/google/callback?code={$code}&state={$state}");
    }

    /**
     * Handle Google OAuth callback
     */
    public function handleCallback(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
            'code_verifier' => 'required|string',
        ]);

        try {
            $identity = $this->googleService->handleCallback(
                $request->code,
                $request->state,
                $request->code_verifier
            );

            if ($identity->user->primary_vault_address) {
                \Log::info('Dispatching SyncUserJob for existing user', [
                    'user_id' => $identity->user_id,
                    'identity_id' => $identity->id,
                ]);
                SyncUserJob::dispatch($identity);
            } else {
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
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}