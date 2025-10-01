<?php

// ================================================================
// app/Services/AptosRegistrationService.php
// ================================================================

namespace App\Services;

use App\Models\User;
use App\Models\ChannelIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class AptosRegistrationService
{
    /**
     * Process user registration on Aptos smart contract
     *
     * @param User $user
     * @param ChannelIdentity $identity
     * @return array
     */
    public function registerUser(User $user, ChannelIdentity $identity): array
    {
        Log::info("Starting Aptos user registration", [
            'user_id' => $user->id,
            'owner_address' => $user->owner_address,
            'channel' => $identity->channel,
            'channel_user_id' => $identity->channel_user_id,
        ]);

        // Validate inputs
        if (!$this->validateRegistrationData($user, $identity)) {
            return [
                'success' => false,
                'error' => 'Invalid registration data'
            ];
        }

        // Prepare request data
        $requestData = [
            'user_id' => $user->id,
            'owner_address' => $user->owner_address,
            'channel' => $identity->channel,
            'channel_user_id' => $identity->channel_user_id,
        ];

        try {
            // Create log directory if it doesn't exist
            $logPath = storage_path('logs/aptos');
            if (!File::exists($logPath)) {
                File::makeDirectory($logPath, 0755, true);
            }

            // Log the request data
            File::append(
                storage_path('logs/aptos/registration-requests.log'),
                '[' . now() . '] Registration request: ' . json_encode($requestData) . PHP_EOL
            );

            // Execute bash script
            $scriptPath = base_path('run-aptos-register.sh');
            $requestJson = json_encode($requestData);
            
            $processArgs = ['/bin/bash', $scriptPath, $requestJson];

            $process = new Process($processArgs);
            $process->setTimeout(120); // 2 minutes timeout
            $process->setWorkingDirectory(base_path());

            Log::info("Executing Aptos registration script");
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // Parse the output (expecting JSON)
            $output = trim($process->getOutput());
            Log::info('Process output: ' . $output);

            $result = json_decode($output, true);

            if (!$result || !isset($result['success'])) {
                throw new \Exception('Invalid response from Aptos script: ' . $output);
            }

            if ($result['success']) {
                // Update user with primary vault address
                $user->update([
                    'primary_vault_address' => $result['vault_address'],
                    'registration_tx_hash' => $result['tx_hash'],
                    'registered_at' => now(),
                ]);

                Log::info("User registration successful", [
                    'user_id' => $user->id,
                    'vault_address' => $result['vault_address'],
                    'tx_hash' => $result['tx_hash'],
                ]);
            }

            return $result;

        } catch (ProcessFailedException $e) {
            Log::error("Aptos registration process failed", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'output' => $e->getProcess()->getOutput(),
                'error_output' => $e->getProcess()->getErrorOutput(),
            ]);

            return [
                'success' => false,
                'error' => 'Process execution failed: ' . $e->getMessage()
            ];

        } catch (\Exception $e) {
            Log::error("Aptos registration exception", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate registration data
     */
    private function validateRegistrationData(User $user, ChannelIdentity $identity): bool
    {
        // Check if owner_address is valid Aptos address
        if (!$user->owner_address || !preg_match('/^0x[a-fA-F0-9]{64}$/', $user->owner_address)) {
            Log::warning("Invalid owner address format", [
                'user_id' => $user->id,
                'owner_address' => $user->owner_address,
            ]);
            return false;
        }

        // Check if channel and channel_user_id exist
        if (!$identity->channel || !$identity->channel_user_id) {
            Log::warning("Missing channel information", [
                'user_id' => $user->id,
                'channel' => $identity->channel,
            ]);
            return false;
        }

        // Check if already registered
        if ($user->primary_vault_address) {
            Log::warning("User already registered", [
                'user_id' => $user->id,
                'vault_address' => $user->primary_vault_address,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Sync user channel identity
     */
    public function syncUserChannel(ChannelIdentity $identity): array
    {
        Log::info("Starting Aptos channel sync", [
            'user_id' => $identity->user_id,
            'channel' => $identity->channel,
            'channel_user_id' => $identity->channel_user_id,
        ]);

        // Prepare request data
        $requestData = [
            'user_id' => $identity->user_id,
            'vault_address' => $identity->user->primary_vault_address,
            'channel' => $identity->channel,
            'channel_user_id' => $identity->channel_user_id,
        ];

        try {
            $scriptPath = base_path('run-aptos-sync.sh');
            $requestJson = json_encode($requestData);
            
            $processArgs = ['/bin/bash', $scriptPath, $requestJson];

            $process = new Process($processArgs);
            $process->setTimeout(120);
            $process->setWorkingDirectory(base_path());

            Log::info("Executing Aptos sync script");
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $output = trim($process->getOutput());
            $result = json_decode($output, true);

            if (!$result || !isset($result['success'])) {
                throw new \Exception('Invalid response from Aptos script');
            }

            if ($result['success']) {
                // Update identity with sync info
                $identity->update([
                    'synced_at' => now(),
                    'sync_tx_hash' => $result['tx_hash'],
                ]);

                Log::info("Channel sync successful", [
                    'identity_id' => $identity->id,
                    'tx_hash' => $result['tx_hash'],
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error("Aptos sync exception", [
                'identity_id' => $identity->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

