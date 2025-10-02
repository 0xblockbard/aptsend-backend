<?php 

namespace App\Jobs;

use App\Models\ChannelIdentity;
use App\Services\AptosRegistrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 180;
    public $maxExceptions = 1;

    protected ChannelIdentity $identity;

    public function __construct(ChannelIdentity $identity)
    {
        $this->identity = $identity;
    }

    public function handle(AptosRegistrationService $service)
    {
        Log::info("===== Sync Aptos Channel Job =====");
        Log::info("Starting sync for channel identity", [
            'identity_id' => $this->identity->id,
            'channel' => $this->identity->channel,
            'user_id' => $this->identity->user_id,
        ]);

        try {
            // Call the service to sync on blockchain
            $result = $service->syncUser($this->identity);

            if (!$result['success']) {
                Log::error("Sync failed", [
                    'identity_id' => $this->identity->id,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);

                $this->fail(new \Exception($result['error'] ?? 'Sync failed'));
                return;
            }

            // Refresh the identity to ensure we have latest data
            $this->identity->refresh();

            // Update the channel identity with vault status
            $this->identity->update([
                'vault_status' => 1, // 1 = linked/active
                'target_vault_address' => $this->identity->user->primary_vault_address,
            ]);

            Log::info("Sync job completed successfully", [
                'identity_id' => $this->identity->id,
                'vault_status' => $this->identity->vault_status,
                'target_vault_address' => $this->identity->target_vault_address,
                'tx_hash' => $result['tx_hash'],
            ]);

        } catch (\Exception $e) {
            Log::error("Sync job exception", [
                'identity_id' => $this->identity->id,
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
            ]);

            $this->fail($e);
        }
    }
}