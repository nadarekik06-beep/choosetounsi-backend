<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\SellerApplicationController;
use App\Http\Controllers\Api\Seller\ProductApiController as SellerProductApi;
use App\Http\Controllers\Api\Seller\OrderApiController as SellerOrderApi;
use App\Http\Controllers\Api\Client\ClientOrderApiController;
use App\Http\Controllers\Api\Client\ProfileApiController;

// ── NEW: Seller Dashboard controllers (added for the seller dashboard UI)
use App\Http\Controllers\Seller\SellerDashboardController;
use App\Http\Controllers\Seller\SellerProductController;
use App\Http\Controllers\Seller\SellerOrderController;

/*
|--------------------------------------------------------------------------
| API Routes - Token Based Authentication (No CSRF)
|--------------------------------------------------------------------------
*/

// ═══════════════════════════════════════════════════════════════════════
// PUBLIC ROUTES
// ═══════════════════════════════════════════════════════════════════════

Route::post('/auth/login',    [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

Route::get('/products',           [ProductController::class, 'index']);
Route::get('/products/featured',  [ProductController::class, 'featured']);
Route::get('/products/{slug}',    [ProductController::class, 'show']);

Route::get('/categories',                    [CategoryController::class, 'index']);
Route::get('/categories/{slug}',             [CategoryController::class, 'show']);
Route::get('/categories/{slug}/products',    [CategoryController::class, 'products']);

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

        // ── Dashboard (NEW — for the seller dashboard UI) ─────────────
        Route::get('/dashboard',          [SellerDashboardController::class, 'index']);

        // ── Products Management (NEW) ─────────────────────────────────
        // /stats MUST be before /{id} to avoid Laravel treating "stats" as an ID
        Route::get('/products/stats',     [SellerProductController::class, 'stats']);
        Route::get('/products',           [SellerProductController::class, 'index']);
        Route::post('/products',          [SellerProductController::class, 'store']);
        Route::get('/products/{id}',      [SellerProductController::class, 'show']);
        Route::put('/products/{id}',      [SellerProductController::class, 'update']);
        Route::delete('/products/{id}',   [SellerProductController::class, 'destroy']);

        // ── Orders Management (NEW) ───────────────────────────────────
        // /stats MUST be before /{id}
        Route::get('/orders/stats',           [SellerOrderController::class, 'stats']);
        Route::get('/orders',                 [SellerOrderController::class, 'index']);
        Route::get('/orders/{id}',            [SellerOrderController::class, 'show']);
        Route::patch('/orders/{id}/status',   [SellerOrderController::class, 'updateStatus']);

        // ── Existing seller routes (keep as-is) ───────────────────────
        Route::get('/statistics',            [SellerProductApi::class, 'statistics']);

        Route::post('/products/{product}/images',           [SellerProductApi::class, 'uploadImages']);
        Route::delete('/products/{product}/images/{image}', [SellerProductApi::class, 'deleteImage']);

        Route::get('/orders/{order}',        [SellerOrderApi::class, 'show']);
        Route::get('/orders/statistics',     [SellerOrderApi::class, 'statistics']);
    });

    // ── Client Routes ─────────────────────────────────────────────────
    Route::prefix('client')->group(function () {
        Route::get('/statistics',     [ClientOrderApiController::class, 'statistics']);
        Route::get('/orders',         [ClientOrderApiController::class, 'index']);
        Route::get('/orders/{order}', [ClientOrderApiController::class, 'show']);
    });

    // ── Admin Routes ──────────────────────────────────────────────────
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/seller-applications',                        [SellerApplicationController::class, 'index']);
        Route::get('/seller-applications/{application}',          [SellerApplicationController::class, 'show']);
        Route::post('/seller-applications/{application}/approve', [SellerApplicationController::class, 'approve']);
        Route::post('/seller-applications/{application}/reject',  [SellerApplicationController::class, 'reject']);

        Route::get('/categories',               [CategoryController::class, 'adminIndex']);
        Route::post('/categories',              [CategoryController::class, 'store']);
        Route::put('/categories/{category}',    [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
    });

});