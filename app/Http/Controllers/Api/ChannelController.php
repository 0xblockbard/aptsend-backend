<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    public function getAllIdentities(Request $request)
    {
        $request->validate([
            'owner_address' => 'required|string',
        ]);

        $user = User::where('owner_address', $request->owner_address)->first();

        if (!$user) {
            return response()->json([
                'identities' => [],
                'primary_vault_address' => null
            ]);
        }

        $identities = $user->channelIdentities()
            ->get()
            ->groupBy('channel')
            ->map(function ($channelGroup) {
                return $channelGroup->map(function ($identity) {
                    return $this->formatIdentity($identity);
                })->values();
            });

        return response()->json([
            'identities' => $identities,
            'primary_vault_address' => $user->primary_vault_address
        ]);
    }

    private function formatIdentity($identity): array
    {
        $base = [
            'id' => $identity->id,
            'identifier' => $this->formatIdentifier($identity),
            'status' => $identity->vault_status === 1 ? 'linked' : 'pending',
        ];

        // Add metadata for channels that need it
        if ($identity->channel === 'evm') {
            $base['metadata'] = [
                'address' => $identity->metadata['address'] ?? $identity->channel_user_id,
                'chain_id' => $identity->metadata['chain_id'] ?? null,
                'chain_name' => $identity->metadata['chain_name'] ?? null,
            ];
        };

        if ($identity->channel === 'sol') {
            $base['metadata'] = [
                'address' => $identity->metadata['address'] ?? $identity->channel_user_id,
                'sns_name' => $identity->metadata['sns_name'] ?? null,
            ];
        }

        return $base;
    }

    private function formatIdentifier($identity): string
    {
        return match($identity->channel) {
            'twitter' => '@' . ($identity->metadata['username'] ?? 'unknown'),
            'telegram' => '@' . ($identity->metadata['username'] ?? 'unknown'),
            'discord' => $identity->metadata['username'] ?? 'unknown',
            'email' => $identity->metadata['email'] ?? 'unknown',
            'evm' => substr($identity->channel_user_id, 0, 6) . '...' . substr($identity->channel_user_id, -4),
            'sol' => substr($identity->channel_user_id, 0, 4) . '...' . substr($identity->channel_user_id, -4),
            default => $identity->channel_user_id,
        };
    }
}