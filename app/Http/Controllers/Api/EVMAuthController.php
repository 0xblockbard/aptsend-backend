<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EVMChannelService;
use App\Jobs\RegisterUserJob;
use App\Jobs\SyncUserJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EVMAuthController extends Controller
{
    public function __construct(
        protected EVMChannelService $evmService
    ) {}

    /**
     * Link EVM wallet (signature already verified on frontend via Reown AppKit)
     */
    public function linkWallet(Request $request)
    {
        Log::info('=== EVM LINK WALLET STARTED ===');
        Log::info('Request data:', $request->all());

        $request->validate([
            'owner_address' => 'required|string',
            'evm_address' => 'required|string|regex:/^0x[a-fA-F0-9]{40}$/',
            'chain_id' => 'required|integer',
        ]);

        try {
            // Create/update identity (signature verification already done on frontend)
            $identity = $this->evmService->linkWallet(
                $request->owner_address,
                $request->evm_address,
                $request->chain_id
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
            Log::error('Failed to link EVM wallet:', [
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