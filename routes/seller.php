<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Seller\SellerDashboardController;
use App\Http\Controllers\Seller\SellerProductController;
use App\Http\Controllers\Seller\SellerOrderController;

/*
|--------------------------------------------------------------------------
| Seller API Routes  →  routes/seller.php
|--------------------------------------------------------------------------
|
| This file is loaded automatically because you already have a separate
| seller.php route file in your routes/ directory.
|
| Make sure RouteServiceProvider maps it, e.g.:
|
|   Route::prefix('api')
|       ->middleware('api')
|       ->namespace($this->namespace)
|       ->group(base_path('routes/seller.php'));
|
| Or simply include it in routes/api.php:
|
|   require __DIR__.'/seller.php';
|
| ─────────────────────────────────────────────────────────────────────────
| DEVELOPMENT NOTE:
| All controllers use hardcoded seller_id = 1.
| When auth is ready, replace with SellerMiddleware and auth()->id().
|
| Route::middleware(['auth:sanctum', 'role:seller'])->prefix('seller')->group(...)
|
*/

Route::prefix('seller')->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/dashboard', [SellerDashboardController::class, 'index']);

    // ── Products ──────────────────────────────────────────────────────────────
    // NOTE: /stats must be defined BEFORE /{id} to avoid route collision
    Route::get('/products/stats', [SellerProductController::class, 'stats']);
    Route::get('/products',       [SellerProductController::class, 'index']);
    Route::post('/products',      [SellerProductController::class, 'store']);
    Route::get('/products/{id}',  [SellerProductController::class, 'show']);
    Route::put('/products/{id}',  [SellerProductController::class, 'update']);
    Route::delete('/products/{id}', [SellerProductController::class, 'destroy']);

    // ── Orders ────────────────────────────────────────────────────────────────
    // NOTE: /stats must be defined BEFORE /{id} to avoid route collision
    Route::get('/orders/stats',           [SellerOrderController::class, 'stats']);
    Route::get('/orders',                 [SellerOrderController::class, 'index']);
    Route::get('/orders/{id}',            [SellerOrderController::class, 'show']);
    Route::patch('/orders/{id}/status',   [SellerOrderController::class, 'updateStatus']);
});