<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Every route in this file is automatically prefixed with /api.

Route::get('/health', HealthController::class);

// Catalog. /products/filters is registered before /products/{slug} (day 4),
// otherwise "filters" would be read as a product slug.
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/filters', [ProductController::class, 'filters']);
Route::get('/products/{slug}', [ProductController::class, 'show']);

// Header autocomplete (day 3). Full results: GET /products?q=
Route::get('/search/suggestions', [SearchController::class, 'suggestions']);

// Laravel default, replaced by /auth/me on day 5.
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
