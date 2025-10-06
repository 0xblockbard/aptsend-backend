<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

// Route::get('/', function () {
//     return view('welcome');
// });

Route::get('/', function () {
    return response()->json([
        'message' => 'API is running',
        'status' => 'ok'
    ]);
});