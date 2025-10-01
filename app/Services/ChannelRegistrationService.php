<?php

namespace App\Services;

use App\Models\ChannelIdentity;
use App\Jobs\RegisterUserJob;
use App\Jobs\SyncUserJob;

class ChannelRegistrationService
{
    /**
     * Should we call register_user() on the smart contract?
     */
    public function shouldRegisterUser(ChannelIdentity $identity): bool
    {
        return is_null($identity->user->primary_vault_address);
    }

    /**
     * Should we call sync_user() on the smart contract?
     */
    public function shouldSyncUser(ChannelIdentity $identity): bool
    {
        return !is_null($identity->user->primary_vault_address);
    }

    /**
     * Process user registration if needed
     */
    public function processRegistrationIfNeeded(ChannelIdentity $identity): void
    {
        if ($this->shouldRegisterUser($identity)) {
            \Log::info('Dispatching user registration job', [
                'user_id' => $identity->user_id,
                'owner_address' => $identity->user->owner_address,
                'channel' => $identity->channel,
                'identity_id' => $identity->id,
            ]);
            
            RegisterUserJob::dispatch($identity->user, $identity);
        }
    }

    /**
     * Process channel sync if needed
     */
    public function processSyncIfNeeded(ChannelIdentity $identity): void
    {
        if ($this->shouldSyncUser($identity)) {
            \Log::info('Dispatching channel sync job', [
                'user_id' => $identity->user_id,
                'owner_address' => $identity->user->owner_address,
                'primary_vault_address' => $identity->user->primary_vault_address,
                'channel' => $identity->channel,
                'identity_id' => $identity->id,
            ]);
            
            SyncUserJob::dispatch($identity);
        }
    }
}