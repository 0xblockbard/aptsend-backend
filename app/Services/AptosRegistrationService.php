<?php

namespace App\Services;

use App\Models\User;
use App\Models\ChannelIdentity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class AptosRegistrationService
{
    /**
     * Process user registration on Aptos smart contract
     */
    public function registerUser(User $user, ChannelIdentity $identity): array
    {
        Log::info("===== Aptos Registration Service - Register User =====");
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
            $process->setTimeout(120);
            $process->setWorkingDirectory(base_path());

            Log::info("Executing Aptos register_user script");
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // Parse the output (expecting JSON)
            $output = $process->getOutput();
            Log::info('Process raw output: ' . $output);

            // Extract only the JSON line (the one that starts with { and ends with })
            preg_match('/\{[^{}]*"success"[^{}]*\}/', $output, $matches);

            if (empty($matches)) {
                throw new \Exception('No JSON found in script output');
            }

            $jsonOutput = $matches[0];
            Log::info('Extracted JSON: ' . $jsonOutput);

            $result = json_decode($jsonOutput, true);

            if (!$result || !isset($result['success'])) {
                throw new \Exception('Invalid JSON from Aptos script');
            }

            if ($result['success']) {
                // Update user with primary vault address
                $user->update([
                    'primary_vault_address' => $result['vault_address'],
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
    public function syncUser(ChannelIdentity $identity): array
    {
        Log::info("Starting Aptos channel sync", [
            'user_id' => $identity->user_id,
            'channel' => $identity->channel,
            'channel_user_id' => $identity->channel_user_id,
        ]);

        $requestData = [
            'user_id' => $identity->user_id,
            'owner_address' => $identity->user->owner_address, 
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

            $output = $process->getOutput();
            Log::info('Process raw output:', ['output' => $output]);

            // Use same regex extraction as registerUser
            preg_match('/\{[^{}]*"success"[^{}]*\}/', $output, $matches);

            if (empty($matches)) {
                Log::error('No JSON found in sync script output', ['output' => $output]);
                throw new \Exception('No JSON found in script output');
            }

            $jsonOutput = $matches[0];
            Log::info('Extracted JSON:', ['json' => $jsonOutput]);

            $result = json_decode($jsonOutput, true);

            if (!$result || !isset($result['success'])) {
                throw new \Exception('Invalid response from Aptos script');
            }

            if ($result['success']) {
                Log::info("Channel sync successful", [
                    'identity_id' => $identity->id,
                    'tx_hash' => $result['tx_hash'] ?? 'unknown',
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