<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\ChannelController;  
use App\Http\Controllers\Api\CheckerController;  
use App\Http\Controllers\Api\EVMAuthController;
use App\Http\Controllers\Api\SolanaAuthController;
use App\Http\Controllers\Api\TwitterAuthController;
use App\Http\Controllers\Api\TelegramAuthController;
use App\Http\Controllers\Api\DiscordAuthController;
use App\Http\Controllers\Api\GoogleAuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('channels/twitter')->group(function () {
    Route::post('/auth-url', [TwitterAuthController::class, 'getAuthUrl']);
    Route::post('/callback', [TwitterAuthController::class, 'handleCallback']);
    Route::get('/callback', [TwitterAuthController::class, 'handleOAuthRedirect']);
});

Route::prefix('channels/google')->group(function () {
    Route::post('/auth-url', [GoogleAuthController::class, 'getAuthUrl']);
    Route::post('/callback', [GoogleAuthController::class, 'handleCallback']);
    Route::get('/callback', [GoogleAuthController::class, 'handleOAuthRedirect']);
});

Route::prefix('channels/discord')->group(function () {
    Route::post('/auth-url', [DiscordAuthController::class, 'getAuthUrl']);
    Route::post('/callback', [DiscordAuthController::class, 'handleCallback']);
    Route::get('/callback', [DiscordAuthController::class, 'handleOAuthRedirect']);
});

Route::prefix('channels/telegram')->group(function () {
    Route::post('/callback', [TelegramAuthController::class, 'handleCallback']);
    Route::post('/unsync', [TelegramAuthController::class, 'unsync']); 
});

Route::prefix('channels/evm')->group(function () {
    Route::post('/link-wallet', [EVMAuthController::class, 'linkWallet']);
});

Route::prefix('channels/sol')->group(function () {
    Route::post('/link-wallet', [SolanaAuthController::class, 'linkWallet']);
    Route::post('/unlink-wallet', [SolanaAuthController::class, 'unlinkWallet']);
});

Route::get('/channels/identities', [ChannelController::class, 'getAllIdentities']);
Route::get('/checker/get-identity', [CheckerController::class, 'getIdentity']);

