<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramChannelService;
use App\Jobs\RegisterUserJob;
use App\Jobs\SyncUserJob;
use Illuminate\Http\Request;

class TelegramAuthController extends Controller
{
    public function __construct(
        protected TelegramChannelService $telegramService
    ) {}

    /**
     * Handle Telegram authentication callback
     * 
     * Receives auth data from Telegram Login Widget (via frontend)
     * Verifies the hash to ensure data is authentic
     * Links Telegram account to user's wallet address
     */
    public function handleCallback(Request $request)
    {
        \Log::info('=== TELEGRAM CALLBACK STARTED ===');
        \Log::info('Request data:', $request->all());

        $request->validate([
            'owner_address' => 'required|string',
            'auth_data' => 'required|array',
            'auth_data.id' => 'required|numeric',
            'auth_data.auth_date' => 'required|numeric',
            'auth_data.hash' => 'required|string',
        ]);

        try {
            $identity = $this->telegramService->handleCallback(
                $request->owner_address,
                $request->auth_data
            );

            \Log::info('Telegram authentication successful', [
                'identity_id' => $identity->id,
                'telegram_id' => $identity->channel_user_id,
            ]);

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
            \Log::error('Telegram callback failed:', [
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
     * Unsync a Telegram account
     * 
     * Removes the link between a Telegram account and wallet address
     */
    public function unsync(Request $request)
    {
        \Log::info('=== TELEGRAM UNSYNC STARTED ===');
        \Log::info('Request data:', $request->all());

        $request->validate([
            'owner_address' => 'required|string',
            'account_id' => 'required|string',
        ]);

        try {
            $this->telegramService->unsyncAccount(
                $request->owner_address,
                $request->account_id
            );

            \Log::info('Telegram account unsynced successfully');

            return response()->json([
                'success' => true,
                'message' => 'Telegram account unlinked successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Telegram unsync failed:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}