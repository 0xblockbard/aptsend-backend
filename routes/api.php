<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\ChannelController;  
use App\Http\Controllers\Api\CheckerController;  
use App\Http\Controllers\Api\TwitterAuthController;
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

Route::get('/channels/identities', [ChannelController::class, 'getAllIdentities']);
Route::get('/checker/get-identity', [CheckerController::class, 'getIdentity']);
