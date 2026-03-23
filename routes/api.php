<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\SellerApplicationController;
use App\Http\Controllers\Api\Client\ClientOrderApiController;
use App\Http\Controllers\Api\Client\ProfileApiController;
use App\Http\Controllers\Api\Client\CartController;
use App\Http\Controllers\Api\Client\FavoriteController;
use App\Http\Controllers\Api\Client\CheckoutController;
use App\Http\Controllers\Api\Seller\SellerDashboardController;
use App\Http\Controllers\Api\Seller\SellerProductController;
use App\Http\Controllers\Api\Seller\SellerOrderController;
use App\Http\Controllers\Admin\SellerController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;

// ═══════════════════════════════════════════════════════════════════════
// PUBLIC ROUTES
// ═══════════════════════════════════════════════════════════════════════

Route::post('/auth/login',    [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

Route::get('/products',          [ProductController::class, 'index']);
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/{slug}',   [ProductController::class, 'show']);

Route::get('/categories',                [CategoryController::class, 'index']);
Route::get('/categories/with-products',  [CategoryController::class, 'withProducts']);
Route::get('/categories/{slug}',         [CategoryController::class, 'show']);
Route::get('/categories/{slug}/products',[CategoryController::class, 'products']);

Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback']);

// ═══════════════════════════════════════════════════════════════════════
// AUTHENTICATED USER ROUTES
// ═══════════════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user',    [AuthController::class, 'user']);

    // Profile
    Route::get('/profile',                 [ProfileApiController::class, 'show']);
    Route::put('/profile',                 [ProfileApiController::class, 'update']);
    Route::put('/profile/password',        [ProfileApiController::class, 'updatePassword']);
    Route::post('/profile/request-seller', [ProfileApiController::class, 'requestSellerRole']);

    // Seller Applications
    Route::post('/seller-applications',       [SellerApplicationController::class, 'store']);
    Route::get('/seller-applications/status', [SellerApplicationController::class, 'status']);

    // CART
    Route::prefix('cart')->group(function () {
        Route::get('/',       [CartController::class, 'index']);
        Route::post('/',      [CartController::class, 'store']);
        Route::put('/{id}',   [CartController::class, 'update']);
        Route::delete('/',    [CartController::class, 'clear']);
        Route::delete('/{id}',[CartController::class, 'destroy']);
    });

    // FAVORITES
    Route::prefix('favorites')->group(function () {
        Route::get('/',                  [FavoriteController::class, 'index']);
        Route::post('/',                 [FavoriteController::class, 'store']);
        Route::delete('/{productId}',    [FavoriteController::class, 'destroy']);
        Route::get('/check/{productId}', [FavoriteController::class, 'check']);
    });

    // CHECKOUT
    Route::post('/checkout', [CheckoutController::class, 'store']);

    // SELLER
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

    // CLIENT
    Route::prefix('client')->group(function () {
        Route::get('/statistics',     [ClientOrderApiController::class, 'statistics']);
        Route::get('/orders',         [ClientOrderApiController::class, 'index']);
        Route::get('/orders/{order}', [ClientOrderApiController::class, 'show']);
    });

    // ADMIN (categories)
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/categories',               [CategoryController::class, 'adminIndex']);
        Route::post('/categories',              [CategoryController::class, 'store']);
        Route::put('/categories/{category}',    [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
    });

    // USER NOTIFICATIONS
    Route::prefix('notifications')->group(function () {
        Route::get('/',             [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/read-all',   [NotificationController::class, 'markAllRead']);
        Route::patch('/{id}/read',  [NotificationController::class, 'markRead']);
    });
});

// ═══════════════════════════════════════════════════════════════════════
// ADMIN PANEL
// ═══════════════════════════════════════════════════════════════════════

Route::prefix('admin')->middleware(['auth:sanctum'])->group(function () {

    Route::put('/sellers/{id}',  [SellerController::class, 'update']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/sellers/{id}',     [SellerController::class, 'destroy']);
    Route::patch('/sellers/{id}/role', [SellerController::class, 'changeRole']);

    Route::get('/sellers',                [SellerController::class, 'index']);
    Route::get('/sellers/{id}',           [SellerController::class, 'show']);
    Route::patch('/sellers/{id}/approve', [SellerController::class, 'approve']);
    Route::patch('/sellers/{id}/reject',  [SellerController::class, 'reject']);
    Route::patch('/sellers/{id}/suspend', [SellerController::class, 'suspend']);

    Route::get('/seller-applications',        [SellerApplicationController::class, 'index']);
    Route::get('/seller-applications/{id}',   [SellerApplicationController::class, 'show']);
    Route::post('/seller-applications/{id}/approve', [SellerApplicationController::class, 'approve']);
    Route::post('/seller-applications/{id}/reject',  [SellerApplicationController::class, 'reject']);
});

// ADMIN NOTIFICATIONS
Route::middleware('auth:admin')->prefix('admin')->group(function () {

    Route::prefix('notifications')->group(function () {
        Route::get('/',             [AdminNotificationController::class, 'index']);
        Route::get('/unread-count', [AdminNotificationController::class, 'unreadCount']);
        Route::patch('/read-all',   [AdminNotificationController::class, 'markAllRead']);
        Route::patch('/{id}/read',  [AdminNotificationController::class, 'markRead']);
    });

    Route::patch('products/{id}/approve', [\App\Http\Controllers\Admin\SellerController::class, 'approveProduct']);
    Route::patch('products/{id}/reject',  [\App\Http\Controllers\Admin\SellerController::class, 'rejectProduct']);
});