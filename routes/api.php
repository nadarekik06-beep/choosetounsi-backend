<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
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
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;   // ← ADDED
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\UserController as AdminUserController;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

Route::post('/auth/login',    [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

Route::get('/products',          [\App\Http\Controllers\Api\ProductController::class, 'index']);
Route::get('/products/featured', [\App\Http\Controllers\Api\ProductController::class, 'featured']);
Route::get('/products/{slug}',   [\App\Http\Controllers\Api\ProductController::class, 'show']);

Route::get('/categories',                 [CategoryController::class, 'index']);
Route::get('/categories/with-products',   [CategoryController::class, 'withProducts']);
Route::get('/categories/{slug}',          [CategoryController::class, 'show']);
Route::get('/categories/{slug}/products', [CategoryController::class, 'products']);

Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback']);

/*
|--------------------------------------------------------------------------
| AUTHENTICATED ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // AUTH
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user',    [AuthController::class, 'user']);

    // PROFILE
    Route::get('/profile',                 [ProfileApiController::class, 'show']);
    Route::put('/profile',                 [ProfileApiController::class, 'update']);
    Route::put('/profile/password',        [ProfileApiController::class, 'updatePassword']);
    Route::post('/profile/request-seller', [ProfileApiController::class, 'requestSellerRole']);

    // SELLER APPLICATIONS
    Route::post('/seller-applications',       [SellerApplicationController::class, 'store']);
    Route::get('/seller-applications/status', [SellerApplicationController::class, 'status']);

    // CART
    Route::prefix('cart')->group(function () {
        Route::get('/',        [CartController::class, 'index']);
        Route::post('/',       [CartController::class, 'store']);
        Route::put('/{id}',    [CartController::class, 'update']);
        Route::delete('/',     [CartController::class, 'clear']);
        Route::delete('/{id}', [CartController::class, 'destroy']);
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

    // NOTIFICATIONS
    Route::prefix('notifications')->group(function () {
        Route::get('/',             [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/read-all',   [NotificationController::class, 'markAllRead']);
        Route::patch('/{id}/read',  [NotificationController::class, 'markRead']);
    });

    /*
    |--------------------------------------------------------------------------
    | SELLER ROUTES
    |--------------------------------------------------------------------------
    */
    Route::prefix('seller')->group(function () {

        Route::get('/dashboard', [SellerDashboardController::class, 'index']);

        Route::get('/products/stats',   [SellerProductController::class, 'stats']);
        Route::get('/products',         [SellerProductController::class, 'index']);
        Route::post('/products',        [SellerProductController::class, 'store']);
        Route::get('/products/{id}',    [SellerProductController::class, 'show']);
        Route::put('/products/{id}',    [SellerProductController::class, 'update']);
        Route::delete('/products/{id}', [SellerProductController::class, 'destroy']);

        Route::delete('/products/{id}/images/{imageId}',        [SellerProductController::class, 'destroyImage']);
        Route::patch('/products/{id}/images/{imageId}/primary', [SellerProductController::class, 'setPrimaryImage']);

        Route::get('/orders/stats',         [SellerOrderController::class, 'stats']);
        Route::get('/orders',               [SellerOrderController::class, 'index']);
        Route::get('/orders/{id}',          [SellerOrderController::class, 'show']);
        Route::patch('/orders/{id}/status', [SellerOrderController::class, 'updateStatus']);
    });

    /*
    |--------------------------------------------------------------------------
    | CLIENT ROUTES
    |--------------------------------------------------------------------------
    */
    Route::prefix('client')->group(function () {
        Route::get('/statistics',     [ClientOrderApiController::class, 'statistics']);
        Route::get('/orders',         [ClientOrderApiController::class, 'index']);
        Route::get('/orders/{order}', [ClientOrderApiController::class, 'show']);
    });

    /*
    |--------------------------------------------------------------------------
    | ADMIN ROUTES
    |--------------------------------------------------------------------------
    */
    Route::prefix('admin')->middleware('role:admin')->group(function () {

        // Categories
        Route::get('/categories',               [CategoryController::class, 'adminIndex']);
        Route::post('/categories',              [CategoryController::class, 'store']);
        Route::put('/categories/{category}',    [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        // Users
        Route::get('/users',              [AdminUserController::class, 'index']);
        Route::get('/users/{id}',         [AdminUserController::class, 'show']);
        Route::put('/users/{id}',         [AdminUserController::class, 'update']);
        Route::patch('/users/{id}/ban',   [AdminUserController::class, 'ban']);
        Route::patch('/users/{id}/unban', [AdminUserController::class, 'unban']);
        Route::delete('/users/{id}',      [AdminUserController::class, 'destroy']);

        // Sellers
        Route::get('/sellers',                [SellerController::class, 'index']);
        Route::get('/sellers/{id}',           [SellerController::class, 'show']);
        Route::put('/sellers/{id}',           [SellerController::class, 'update']);
        Route::delete('/sellers/{id}',        [SellerController::class, 'destroy']);
        Route::patch('/sellers/{id}/role',    [SellerController::class, 'changeRole']);
        Route::patch('/sellers/{id}/approve', [SellerController::class, 'approve']);
        Route::patch('/sellers/{id}/reject',  [SellerController::class, 'reject']);
        Route::patch('/sellers/{id}/suspend', [SellerController::class, 'suspend']);

        // Seller applications
        Route::get('/seller-applications',                        [SellerApplicationController::class, 'index']);
        Route::get('/seller-applications/{id}',                   [SellerApplicationController::class, 'show']);
        Route::post('/seller-applications/{application}/approve', [SellerApplicationController::class, 'approve']);
        Route::post('/seller-applications/{application}/reject',  [SellerApplicationController::class, 'reject']);

        // Products
        Route::get('/products',                [AdminProductController::class, 'index']);
        Route::get('/products/{id}',           [AdminProductController::class, 'show']);
        Route::put('/products/{id}',           [AdminProductController::class, 'update']);
        Route::patch('/products/{id}/approve', [AdminProductController::class, 'approve']);
        Route::patch('/products/{id}/reject',  [AdminProductController::class, 'reject']);
        Route::patch('/products/{id}/disable', [AdminProductController::class, 'disable']);
        Route::delete('/products/{id}',        [AdminProductController::class, 'destroy']);

        // ── ORDERS ────────────────────────────────────────────────  ← ADDED BLOCK
        Route::get('/orders/stats',         [AdminOrderController::class, 'stats']);
        Route::get('/orders',               [AdminOrderController::class, 'index']);
        Route::get('/orders/{id}',          [AdminOrderController::class, 'show']);
        Route::patch('/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);

        // Admin Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/',             [AdminNotificationController::class, 'index']);
            Route::get('/unread-count', [AdminNotificationController::class, 'unreadCount']);
            Route::patch('/read-all',   [AdminNotificationController::class, 'markAllRead']);
            Route::patch('/{id}/read',  [AdminNotificationController::class, 'markRead']);
        });
    });
});