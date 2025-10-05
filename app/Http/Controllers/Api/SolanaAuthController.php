<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SolanaChannelService;
use App\Jobs\RegisterUserJob;
use App\Jobs\SyncUserJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SolanaAuthController extends Controller
{
    public function __construct(
        protected SolanaChannelService $solanaService
    ) {}

    /**
     * Link Solana wallet (signature already verified on frontend)
     */
    public function linkWallet(Request $request)
    {
        Log::info('=== SOLANA LINK WALLET STARTED ===');
        Log::info('Request data:', $request->all());

        $request->validate([
            'owner_address' => 'required|string',
            'solana_address' => 'required|string',
        ]);

        try {
            // Create/update identity (signature verification already done on frontend)
            $identity = $this->solanaService->linkWallet(
                $request->owner_address,
                $request->solana_address
            );

            // Dispatch appropriate job based on user's vault status
            if ($identity->user->primary_vault_address) {
                Log::info('Dispatching SyncUserJob for existing user', [
                    'user_id' => $identity->user_id,
                    'identity_id' => $identity->id,
                ]);
                SyncUserJob::dispatch($identity);
            } else {
                Log::info('Dispatching RegisterUserJob for new user', [
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
            Log::error('Failed to link Solana wallet:', [
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
     * Unlink Solana wallet
     */
    public function unlinkWallet(Request $request)
    {
        Log::info('=== SOLANA UNLINK WALLET STARTED ===');
        Log::info('Request data:', $request->all());

        $request->validate([
            'owner_address' => 'required|string',
            'identity_id' => 'required|string',
        ]);

        try {
            $this->solanaService->unlinkWallet(
                $request->owner_address,
                $request->identity_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Solana wallet unlinked successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to unlink Solana wallet:', [
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