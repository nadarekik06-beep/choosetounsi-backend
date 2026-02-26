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

/*
|--------------------------------------------------------------------------
| API Routes - Token Based Authentication (No CSRF)
|--------------------------------------------------------------------------
| All routes use Laravel Sanctum for authentication via Bearer tokens
| Public routes are accessible without authentication
| Protected routes require: Authorization: Bearer {token}
|--------------------------------------------------------------------------
*/

// ═══════════════════════════════════════════════════════════════════════
// PUBLIC ROUTES - No authentication required
// ═══════════════════════════════════════════════════════════════════════

// ────────────────────────────────────────────
// Authentication
// ────────────────────────────────────────────
Route::post('/auth/login',    [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

// ────────────────────────────────────────────
// Public Products (For Homepage & Browse)
// ────────────────────────────────────────────
Route::get('/products',           [ProductController::class, 'index']);      // All approved products
Route::get('/products/featured',  [ProductController::class, 'featured']);   // Featured products
Route::get('/products/{slug}',    [ProductController::class, 'show']);       // Single product by slug

// ────────────────────────────────────────────
// Categories
// ────────────────────────────────────────────
Route::get('/categories',                    [CategoryController::class, 'index']);    // All categories
Route::get('/categories/{slug}',             [CategoryController::class, 'show']);     // Single category
Route::get('/categories/{slug}/products',    [CategoryController::class, 'products']); // Products in category

// ═══════════════════════════════════════════════════════════════════════
// PROTECTED ROUTES - Authentication Required (Bearer Token)
// ═══════════════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // ────────────────────────────────────────────
    // Authentication
    // ────────────────────────────────────────────
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user',    [AuthController::class, 'user']);

    // ────────────────────────────────────────────
    // Profile Management (All Authenticated Users)
    // ────────────────────────────────────────────
    Route::get('/profile',                 [ProfileApiController::class, 'show']);
    Route::put('/profile',                 [ProfileApiController::class, 'update']);
    Route::put('/profile/password',        [ProfileApiController::class, 'updatePassword']);
    Route::post('/profile/request-seller', [ProfileApiController::class, 'requestSellerRole']);

    // ────────────────────────────────────────────
    // Seller Applications (Clients Can Apply)
    // ────────────────────────────────────────────
    Route::post('/seller-applications',        [SellerApplicationController::class, 'store']);   // Submit application
    Route::get('/seller-applications/status',  [SellerApplicationController::class, 'status']);  // Check application status

    // ────────────────────────────────────────────
    // Seller Routes (Approved Sellers Only)
    // ────────────────────────────────────────────
    Route::prefix('seller')->group(function () {
        
        // Dashboard Statistics
        Route::get('/statistics', [SellerProductApi::class, 'statistics']);
        // routes/api.php
        Route::get('/sellers/featured', [SellerController::class, 'featured']);

        // Product Management (with multiple images)
        Route::get('/products',              [SellerProductApi::class, 'index']);      // List seller's products
        Route::post('/products',             [SellerProductApi::class, 'store']);      // Create product with images
        Route::get('/products/{product}',    [SellerProductApi::class, 'show']);       // View single product
        Route::put('/products/{product}',    [SellerProductApi::class, 'update']);     // Update product
        Route::delete('/products/{product}', [SellerProductApi::class, 'destroy']);    // Delete product
        
        // Product Images
        Route::post('/products/{product}/images',           [SellerProductApi::class, 'uploadImages']);  // Add more images
        Route::delete('/products/{product}/images/{image}', [SellerProductApi::class, 'deleteImage']);   // Delete image
        
        // Orders (Read-only)
        Route::get('/orders',         [SellerOrderApi::class, 'index']);          // List orders
        Route::get('/orders/{order}', [SellerOrderApi::class, 'show']);           // View single order
        Route::get('/orders/statistics', [SellerOrderApi::class, 'statistics']);  // Order statistics
    });

    // ────────────────────────────────────────────
    // Client Routes (Clients Only)
    // ────────────────────────────────────────────
    Route::prefix('client')->group(function () {
        
        // Dashboard Statistics
        Route::get('/statistics', [ClientOrderApiController::class, 'statistics']);
        
        // Order Management
        Route::get('/orders',         [ClientOrderApiController::class, 'index']);  // List client's orders
        Route::get('/orders/{order}', [ClientOrderApiController::class, 'show']);   // View single order
    });

    // ────────────────────────────────────────────
    // Admin Routes (Admin Only)
    // ────────────────────────────────────────────
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        
        // Seller Applications Management
        Route::get('/seller-applications',                     [SellerApplicationController::class, 'index']);    // List all applications
        Route::get('/seller-applications/{application}',       [SellerApplicationController::class, 'show']);     // View application
        Route::post('/seller-applications/{application}/approve', [SellerApplicationController::class, 'approve']); // Approve
        Route::post('/seller-applications/{application}/reject',  [SellerApplicationController::class, 'reject']);  // Reject
        
        // Category Management
        Route::get('/categories',              [CategoryController::class, 'adminIndex']);  // List all
        Route::post('/categories',             [CategoryController::class, 'store']);       // Create
        Route::put('/categories/{category}',   [CategoryController::class, 'update']);      // Update
        Route::delete('/categories/{category}',[CategoryController::class, 'destroy']);     // Delete
    });

});