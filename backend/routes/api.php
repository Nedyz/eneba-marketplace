<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Every route in this file is automatically prefixed with /api.

Route::get('/health', HealthController::class);

// Laravel default, replaced by /auth/me on day 5.
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
