<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;


Route::get('/', function () {
    return view('welcome');
});


// Route::get('/auth/twitter/callback', function (Request $request) {
//     $code = $request->query('code');
//     $state = $request->query('state');
//     $error = $request->query('error');
    
//     $frontendUrl = config('app.frontend_url', 'http://localhost:5174');
    
//     if ($error) {
//         return redirect("{$frontendUrl}/dashboard?twitter_error={$error}");
//     }
    
//     return redirect("{$frontendUrl}/auth/twitter/callback?code={$code}&state={$state}");
// });

// Route::get('/auth/twitter/callback', function (Request $request) {
//     $code = $request->query('code');
//     $state = $request->query('state');
//     $error = $request->query('error');
//     $errorDescription = $request->query('error_description');
    
//     $frontendUrl = config('app.frontend_url', 'http://localhost:5174');
    
//     if ($error) {
//         $errorMessage = urlencode($errorDescription ?: $error);
//         return redirect("{$frontendUrl}/dashboard?twitter_error={$errorMessage}");
//     }
    
//     return redirect("{$frontendUrl}/auth/twitter/callback?code={$code}&state={$state}");
// });