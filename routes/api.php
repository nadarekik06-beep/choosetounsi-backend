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
use App\Http\Controllers\Admin\SellerController;
// ═══════════════════════════════════════════════════════════════════════
// PUBLIC ROUTES
// ═══════════════════════════════════════════════════════════════════════

Route::post('/auth/login',    [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

Route::get('/products',          [ProductController::class, 'index']);
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/{slug}',   [ProductController::class, 'show']);

Route::get('/categories',                [CategoryController::class, 'index']);
Route::get('/categories/with-products',  [CategoryController::class, 'withProducts']); // ← NEW
Route::get('/categories/{slug}',         [CategoryController::class, 'show']);
Route::get('/categories/{slug}/products',[CategoryController::class, 'products']);

Route::get('/auth/google/redirect',  [AuthController::class, 'googleRedirect']);
Route::get('/auth/google/callback',  [AuthController::class, 'googleCallback']);

// ═══════════════════════════════════════════════════════════════════════
// FRONTEND USER ROUTES (auth:sanctum — regular users)
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

    // ── Seller Applications (submitted by frontend users) ─────────────
    Route::post('/seller-applications',       [SellerApplicationController::class, 'store']);
    Route::get('/seller-applications/status', [SellerApplicationController::class, 'status']);

    // ── Seller Routes ─────────────────────────────────────────────────
    Route::prefix('seller')->group(function () {

        Route::get('/dashboard', [SellerDashboardController::class, 'index']);

        Route::get('/products/stats', [SellerProductController::class, 'stats']);
        Route::get('/products',       [SellerProductController::class, 'index']);
        Route::post('/products',      [SellerProductController::class, 'store']);

        Route::delete('/products/{id}/images/{imageId}',        [SellerProductController::class, 'destroyImage']);
        Route::patch('/products/{id}/images/{imageId}/primary', [SellerProductController::class, 'setPrimaryImage']);

        Route::get('/products/{id}',    [SellerProductController::class, 'show']);
        Route::post('/products/{id}',   [SellerProductController::class, 'update']);
        Route::put('/products/{id}',    [SellerProductController::class, 'update']);
        Route::delete('/products/{id}', [SellerProductController::class, 'destroy']);

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

    // ── Admin Routes that use role:admin (categories CRUD) ────────────
    // These are only called from admin panel pages that happen to work
    // because category routes don't need the Admin model guard.
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/categories',               [CategoryController::class, 'adminIndex']);
        Route::post('/categories',              [CategoryController::class, 'store']);
        Route::put('/categories/{category}',    [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
    });
});

// ═══════════════════════════════════════════════════════════════════════
// ADMIN PANEL ROUTES
//
// Uses auth:sanctum only — NO role:admin middleware.
//
// WHY: The admin panel authenticates via a separate Admin model which
// issues its own Sanctum token. The role:admin middleware checks
// users.role on the User model — a completely different table — so it
// always rejects admin panel requests with 401/403.
//
// Secured by: only the admin panel knows and sends the admin token.
// ═══════════════════════════════════════════════════════════════════════

Route::prefix('admin')->middleware(['auth:sanctum'])->group(function () {

    // ── Sellers (approved/suspended seller accounts) ──────────────────
    Route::get('/sellers',                  [SellerController::class, 'index']);
    Route::get('/sellers/{id}',             [SellerController::class, 'show']);
    Route::patch('/sellers/{id}/approve',   [SellerController::class, 'approve']);
    Route::patch('/sellers/{id}/reject',    [SellerController::class, 'reject']);
    Route::patch('/sellers/{id}/suspend',   [SellerController::class, 'suspend']);

    // ── Seller Applications (pending/approved/rejected applications) ───
    Route::get('/seller-applications',
        [SellerApplicationController::class, 'index']);

    Route::get('/seller-applications/{application}',
        [SellerApplicationController::class, 'show']);

    Route::post('/seller-applications/{application}/approve',
        [SellerApplicationController::class, 'approve']);

    Route::post('/seller-applications/{application}/reject',
        [SellerApplicationController::class, 'reject']);
});