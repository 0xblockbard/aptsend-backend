<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IdentifierResolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckerController extends Controller
{
    protected $identifierResolver;

    public function __construct(IdentifierResolverService $identifierResolver)
    {
        $this->identifierResolver = $identifierResolver;
    }

    public function getIdentity(Request $request)
    {
        $request->validate([
            'channel' => 'required|in:twitter,telegram,google,discord,evm,sol',
            'identifier' => 'required|string|max:255',
        ]);

        $channel = $request->query('channel');
        $identifier = trim($request->query('identifier'));

        Log::info('Checker request received', [
            'channel' => $channel,
            'identifier' => $identifier,
            'raw_identifier' => $request->query('identifier')
        ]);

        try {
            $channelUserId = $this->identifierResolver->resolve($channel, $identifier);

            return response()->json([
                'success' => true,
                'channel' => $channel,
                'identifier' => $identifier,
                'channel_user_id' => $channelUserId
            ]);

        } catch (\Exception $e) {
            Log::error('Identifier resolution failed', [
                'channel' => $channel,
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}