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
            $result = $service->registerUser($this->user, $this->identity);

            if (!$result['success']) {
                Log::error("Registration failed", [
                    'user_id' => $this->user->id,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);

                $this->fail(new \Exception($result['error'] ?? 'Registration failed'));
                return;
            }

            Log::info("Registration job completed successfully", [
                'user_id' => $this->user->id,
                'vault_address' => $result['vault_address'] ?? null,
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