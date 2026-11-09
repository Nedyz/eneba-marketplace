<?php

use Illuminate\Support\Facades\Route;

// This backend is a JSON API only; the UI is the separate React app.
// The root URL just identifies the service and points to the health check.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'health' => url('/api/health'),
]));
