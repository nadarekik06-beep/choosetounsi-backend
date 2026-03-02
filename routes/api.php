<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\SellerApplicationController;
use App\Http\Controllers\Api\Client\ClientOrderApiController;
use App\Http\Controllers\Api\Client\ProfileApiController;

use App\Http\Controllers\Api\Seller\SellerDashboardController;
use App\Http\Controllers\Api\Seller\SellerProductController;
use App\Http\Controllers\Api\Seller\SellerOrderController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ═══════════════════════════════════════════════════════════════════════
// PUBLIC ROUTES
// ═══════════════════════════════════════════════════════════════════════

Route::post('/auth/login',    [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

// Products (public browse)
Route::get('/products',          [ProductController::class, 'index']);
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/{slug}',   [ProductController::class, 'show']);

// Categories (public)
Route::get('/categories',                 [CategoryController::class, 'index']);
Route::get('/categories/{slug}',          [CategoryController::class, 'show']);
Route::get('/categories/{slug}/products', [CategoryController::class, 'products']);

// ═══════════════════════════════════════════════════════════════════════
// PROTECTED ROUTES
// ═══════════════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // ── Auth ──────────────────────────────────────────────────────────
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user',    [AuthController::class, 'user']);

    // ── Profile ───────────────────────────────────────────────────────
    Route::get('/profile',                 [ProfileApiController::class, 'show']);
    Route::put('/profile',                 [ProfileApiController::class, 'update']);
    Route::put('/profile/password',        [ProfileApiController::class, 'updatePassword']);
    Route::post('/profile/request-seller', [ProfileApiController::class, 'requestSellerRole']);

    // ── Seller Applications ───────────────────────────────────────────
    Route::post('/seller-applications',       [SellerApplicationController::class, 'store']);
    Route::get('/seller-applications/status', [SellerApplicationController::class, 'status']);

    // ── Seller Routes ─────────────────────────────────────────────────
    Route::prefix('seller')->group(function () {

        Route::get('/dashboard', [SellerDashboardController::class, 'index']);

        // IMPORTANT: /stats must come before /{id} to avoid route param conflict
        Route::get('/products/stats', [SellerProductController::class, 'stats']);
        Route::get('/products',       [SellerProductController::class, 'index']);
        Route::post('/products',      [SellerProductController::class, 'store']);

        // Image management must come before /{id} routes
        Route::delete('/products/{id}/images/{imageId}',        [SellerProductController::class, 'destroyImage']);
        Route::patch('/products/{id}/images/{imageId}/primary', [SellerProductController::class, 'setPrimaryImage']);

        // Single product CRUD
        Route::get('/products/{id}',    [SellerProductController::class, 'show']);
        Route::post('/products/{id}',   [SellerProductController::class, 'update']);
        Route::put('/products/{id}',    [SellerProductController::class, 'update']);
        Route::delete('/products/{id}', [SellerProductController::class, 'destroy']);

        // Orders — /stats must come before /{id}
        Route::get('/orders/stats',         [SellerOrderController::class, 'stats']);
        Route::get('/orders',               [SellerOrderController::class, 'index']);
        Route::get('/orders/{id}',          [SellerOrderController::class, 'show']);
        Route::patch('/orders/{id}/status', [SellerOrderController::class, 'updateStatus']);
    });

    // ── Client Routes ─────────────────────────────────────────────────
    Route::prefix('client')->group(function () {
        Route::get('/statistics',     [ClientOrderApiController::class, 'statistics']);
        Route::get('/orders',         [ClientOrderApiController::class, 'index']);
        Route::get('/orders/{order}', [ClientOrderApiController::class, 'show']);
    });

    // ── Admin Routes ──────────────────────────────────────────────────
    Route::prefix('admin')->middleware('role:admin')->group(function () {

        // Seller applications
        Route::get('/seller-applications',                        [SellerApplicationController::class, 'index']);
        Route::get('/seller-applications/{application}',          [SellerApplicationController::class, 'show']);
        Route::post('/seller-applications/{application}/approve', [SellerApplicationController::class, 'approve']);
        Route::post('/seller-applications/{application}/reject',  [SellerApplicationController::class, 'reject']);

        // Categories (admin CRUD)
        Route::get('/categories',               [CategoryController::class, 'adminIndex']);
        Route::post('/categories',              [CategoryController::class, 'store']);
        Route::put('/categories/{category}',    [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
    });
});
