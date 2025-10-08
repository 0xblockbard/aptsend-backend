<?php

namespace App\Services;

use App\Models\Transfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class AptosTransferService
{
    /**
     * Process a single unprocessed transfer
     *
     * @return array
     */
    public function processSingleTransfer(): array
    {
        Log::info("Checking for unprocessed Aptos transfer");
        
        // Use a database transaction to ensure we don't process the same record in parallel
        DB::beginTransaction();
        
        try {
            // Lock and get ONE unprocessed transfer atomically
            $transfer = Transfer::where('status', Transfer::STATUS_PENDING)
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();
                
            if (!$transfer) {
                DB::commit();
                Log::info('No unprocessed transfers found.');
                return ['processed' => 0];
            }
            
            Log::info("Found transfer {$transfer->id} to process");
            
            // Mark transfer as processing BEFORE validation to prevent other jobs from picking it up
            $transfer->update(['status' => Transfer::STATUS_PROCESSING]);
            
            // Validate the transfer
            $validation = $this->validateTransfer($transfer);
            
            if (!$validation['valid']) {
                DB::commit();
                
                // Mark as failed with validation error
                $transfer->markAsFailed($validation['error']);
                
                Log::warning("Transfer {$transfer->id} validation failed: {$validation['error']}");
                return ['processed' => 0, 'invalid' => 1];
            }
            
            // Prepare transfer data - use fields directly from transfer model
            $transferData = [
                'id' => $transfer->id,
                'from_channel' => $transfer->from_channel,
                'from_user_id' => $transfer->from_user_id,
                'to_channel' => $transfer->to_channel,
                'to_user_id' => $transfer->to_user_id,
                'amount' => (int) $transfer->amount,
            ];
            
            // Commit the transaction to release the lock
            DB::commit();
            
        } catch (\Exception $e) {
            // If anything goes wrong, roll back to release locks
            DB::rollBack();
            Log::error("Transaction failed during transfer processing: " . $e->getMessage());
            return [
                'processed' => 0,
                'error' => 'Database transaction failed: ' . $e->getMessage()
            ];
        }
        
        // Process the transfer via TypeScript
        try {
            $this->executeTransferScript($transferData);
            
            Log::info("Completed processing transfer {$transfer->id}");
            
            return ['processed' => 1];
            
        } catch (ProcessFailedException $e) {
            Log::error("Process failed for transfer {$transfer->id}: " . $e->getMessage());
            Log::error('Process output: ' . $e->getProcess()->getOutput());
            Log::error('Process error output: ' . $e->getProcess()->getErrorOutput());
            
            // Mark transfer as failed
            $transfer->markAsFailed("Process execution failed: " . $e->getMessage());
            
            return [
                'processed' => 0,
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error("Exception while processing transfer {$transfer->id}: " . $e->getMessage());
            
            // Mark transfer as failed
            $transfer->markAsFailed("Failed to process: " . $e->getMessage());
            
            return [
                'processed' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Execute the transfer TypeScript script via bash wrapper
     *
     * @param array $transferData
     * @return void
     */
    protected function executeTransferScript(array $transferData): void
    {
        $requestJson = json_encode($transferData);
        
        Log::info('Calling Aptos transfer script: ' . $requestJson);

        // Use bash script wrapper
        $scriptPath = base_path('run-transfer.sh');
        $processArgs = ['/bin/bash', $scriptPath, $requestJson];

        $process = new Process($processArgs);
        $process->setTimeout(120); // 2 minutes timeout for a single transfer
        $process->setWorkingDirectory(base_path());
        
        // Run the process synchronously
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        
        // Log output for debugging
        if ($process->getErrorOutput()) {
            Log::error('Process error output: ' . $process->getErrorOutput());
        }
    }
    
    /**
     * Check if there are any unprocessed transfers
     *
     * @return bool
     */
    public function hasUnprocessedTransfers(): bool
    {
        return Transfer::where('status', Transfer::STATUS_PENDING)->exists();
    }
    
    /**
     * Get count of unprocessed transfers
     *
     * @return int
     */
    public function getUnprocessedCount(): int
    {
        return Transfer::where('status', Transfer::STATUS_PENDING)->count();
    }
    
    /**
     * Validate a transfer
     *
     * @param Transfer $transfer
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private function validateTransfer(Transfer $transfer): array
    {
        // Check required fields exist
        if (!$transfer->from_channel || !$transfer->from_user_id) {
            return [
                'valid' => false,
                'error' => 'Missing sender information',
            ];
        }

        if (!$transfer->to_channel || !$transfer->to_user_id) {
            return [
                'valid' => false,
                'error' => 'Missing recipient information',
            ];
        }

        // Check amount is positive
        if ($transfer->amount <= 0) {
            return [
                'valid' => false,
                'error' => 'Amount must be greater than 0',
            ];
        }

        // Optionally check minimum transfer amount
        $minAmount = config('aptos.min_transfer_amount', 1000);
        if ($transfer->amount < $minAmount) {
            return [
                'valid' => false,
                'error' => "Amount must be at least {$minAmount} Octas",
            ];
        }

        return [
            'valid' => true,
            'error' => null,
        ];
    }
}