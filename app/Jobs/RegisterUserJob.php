<?php 

namespace App\Jobs;

use App\Models\User;
use App\Models\ChannelIdentity;
use App\Services\AptosRegistrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RegisterUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 180;
    public $maxExceptions = 1;

    protected User $user;
    protected ChannelIdentity $identity;

    public function __construct(User $user, ChannelIdentity $identity)
    {
        $this->user = $user;
        $this->identity = $identity;
    }

    public function handle(AptosRegistrationService $service)
    {
        Log::info("===== Register Aptos User Job =====");
        Log::info("Starting registration for user", [
            'user_id' => $this->user->id,
            'owner_address' => $this->user->owner_address,
        ]);

        try {
            // Call the service to register on blockchain
            $result = $service->registerUser($this->user, $this->identity);

            if (!$result['success']) {
                Log::error("Registration failed", [
                    'user_id' => $this->user->id,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);

                $this->fail(new \Exception($result['error'] ?? 'Registration failed'));
                return;
            }

            // Refresh models to get the updated data from the service
            $this->user->refresh();
            $this->identity->refresh();

            // Update the channel identity with vault status and target address
            $this->identity->update([
                'vault_status' => 1, // 1 = linked/active
                'target_vault_address' => $result['vault_address'],
            ]);

            Log::info("Registration job completed successfully", [
                'user_id' => $this->user->id,
                'primary_vault_address' => $this->user->primary_vault_address,
                'identity_id' => $this->identity->id,
                'vault_status' => $this->identity->vault_status,
                'target_vault_address' => $this->identity->target_vault_address,
                'tx_hash' => $result['tx_hash'],
            ]);

        } catch (\Exception $e) {
            Log::error("Registration job exception", [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
            ]);

            $this->fail($e);
        }
    }
}