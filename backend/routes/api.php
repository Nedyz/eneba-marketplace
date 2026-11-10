<?php

use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Every route in this file is automatically prefixed with /api.

Route::get('/health', HealthController::class);

// Homepage (day 4): /home is the layout from home_blocks (D4); the other three
// are the content of the blocks the seeded layout points to (D3, D32).
Route::get('/home', [HomeController::class, 'layout']);
Route::get('/home/banners', [HomeController::class, 'banners']);
Route::get('/home/best-sellers', [HomeController::class, 'bestSellers']);
Route::get('/home/deals', [HomeController::class, 'deals']);

// Catalog. /products/filters is registered before /products/{slug} (day 4),
// otherwise "filters" would be read as a product slug.
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/filters', [ProductController::class, 'filters']);
Route::get('/products/{slug}', [ProductController::class, 'show']);

// Header autocomplete (day 3). Full results: GET /products?q=
Route::get('/search/suggestions', [SearchController::class, 'suggestions']);

// Cart (day 4): the cart lives in the browser (D8); this prices it from the
// database (D31). Public: guests fill a cart before signing in.
Route::post('/cart/quote', [CartController::class, 'quote']);

// Laravel default, replaced by /auth/me on day 5.
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
