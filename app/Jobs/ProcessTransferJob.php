<?php 

namespace App\Jobs;

use App\Services\AptosTransferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTransferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 120;
    public $maxExceptions = 1;

    public function __construct()
    {
        $this->tries = 1;
    }

    public function handle(AptosTransferService $service)
    {
        Log::info("===== Process Aptos Transfer Job =====");
        
        try {
            // Service will find and process ONE unprocessed transfer
            $result = $service->processSingleTransfer();
            
            if ($result['processed'] > 0) {
                Log::info("ProcessTransferJob completed successfully - processed {$result['processed']} transfer");
            } else if (isset($result['invalid'])) {
                Log::warning("ProcessTransferJob found invalid transfer");
            } else {
                Log::info("ProcessTransferJob - no transfers to process");
            }
            
        } catch (\Exception $e) {
            Log::error("ProcessTransferJob failed", [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString()
            ]);
            
            // Fail the job without retrying
            $this->fail($e);
        }
    }
}