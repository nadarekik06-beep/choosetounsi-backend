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
| Protected by auth:sanctum + role:seller middleware.
| seller_id is now resolved from auth()->id() — no more hardcoding.
|
| How to include this file: add this line to routes/api.php:
|
|   require __DIR__.'/seller.php';
|
*/

Route::middleware(['auth:sanctum', 'role:seller'])
    ->prefix('seller')
    ->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/dashboard', [SellerDashboardController::class, 'index']);

    // ── Products ──────────────────────────────────────────────────────────────
    // NOTE: /stats must be BEFORE /{id} to avoid route collision
    Route::get('/products/stats',     [SellerProductController::class, 'stats']);
    Route::get('/products',           [SellerProductController::class, 'index']);
    Route::post('/products',          [SellerProductController::class, 'store']);
    Route::get('/products/{id}',      [SellerProductController::class, 'show']);
    Route::put('/products/{id}',      [SellerProductController::class, 'update']);
    Route::delete('/products/{id}',   [SellerProductController::class, 'destroy']);

    // ── Orders ────────────────────────────────────────────────────────────────
    // NOTE: /stats must be BEFORE /{id} to avoid route collision
    Route::get('/orders/stats',           [SellerOrderController::class, 'stats']);
    Route::get('/orders',                 [SellerOrderController::class, 'index']);
    Route::get('/orders/{id}',            [SellerOrderController::class, 'show']);
    Route::patch('/orders/{id}/status',   [SellerOrderController::class, 'updateStatus']);
});