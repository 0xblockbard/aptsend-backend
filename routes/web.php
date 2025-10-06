<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/', function () {
    return response()->json([
        'message' => 'API is running',
        'status' => 'ok'
    ]);
});

Route::get('/login/{user}', function ($user) {
    if (! request()->hasValidSignature()) {
        abort(401);
    }
    
    $user = User::findOrFail($user);
    Auth::login($user);
    
    return redirect('/horizon');
    
})->name('login.auto');