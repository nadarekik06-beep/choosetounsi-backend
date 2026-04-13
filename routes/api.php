<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\SubcategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SellerApplicationController;
use App\Http\Controllers\Api\Client\ClientOrderApiController;
use App\Http\Controllers\Api\Client\ProfileApiController;
use App\Http\Controllers\Api\Client\CartController;
use App\Http\Controllers\Api\Client\FavoriteController;
use App\Http\Controllers\Api\Client\CheckoutController;
use App\Http\Controllers\Api\Seller\SellerDashboardController;
use App\Http\Controllers\Api\Seller\SellerProductController;
use App\Http\Controllers\Api\Seller\SellerOrderController;
use App\Http\Controllers\Api\Seller\ProductUpdateRequestController as SellerProductUpdateRequestController;
use App\Http\Controllers\Admin\SellerController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductUpdateRequestController as AdminProductUpdateRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Client\ComplaintController as ClientComplaintController;
use App\Http\Controllers\Api\Seller\SellerComplaintController;
use App\Http\Controllers\Admin\AdminComplaintController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminSubcategoryController;
use App\Http\Controllers\Admin\AdminAttributeController;   // ← ADD THIS LINE
use App\Http\Controllers\Api\Client\AddressController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\Seller\SellerSubscriptionController;
use App\Http\Controllers\AIController;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

Route::post('/auth/login',    [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback']);

Route::get('/products',          [ProductController::class, 'index']);
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/{slug}',   [ProductController::class, 'show']);

Route::get('/categories',                 [CategoryController::class, 'index']);
Route::get('/categories/with-products',   [CategoryController::class, 'withProducts']);
Route::get('/categories/{slug}',          [CategoryController::class, 'show']);
Route::get('/categories/{slug}/products', [CategoryController::class, 'products']);

Route::get('/categories/{slug}/subcategories',     [SubcategoryController::class, 'index']);
Route::get('/subcategories/{id}/attributes',       [SubcategoryController::class, 'attributes']);
Route::get('/categories/{slug}/filter-attributes', [ProductController::class, 'filterAttributes']);
Route::post('/ai/chat', [\App\Http\Controllers\Api\AiChatController::class, 'handle']);

Route::post('/search/text',  [\App\Http\Controllers\Api\SearchController::class, 'searchText']);
Route::post('/search/image', [\App\Http\Controllers\Api\SearchController::class, 'searchImage']);
Route::post('/products/by-ids', [ProductController::class, 'byIds']);
Route::get('/products',          [ProductController::class, 'index']);
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/{slug}',   [ProductController::class, 'show']);
// This must come BEFORE the auth:sanctum group
Route::post(
    '/payment/stripe/webhook',
    [\App\Http\Controllers\Api\Client\PaymentController::class, 'stripeWebhook']
)->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);



/*
|--------------------------------------------------------------------------
| AUTHENTICATED ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user',    [AuthController::class, 'user']);

    Route::get('/profile',                 [ProfileApiController::class, 'show']);
    Route::put('/profile',                 [ProfileApiController::class, 'update']);
    Route::put('/profile/password',        [ProfileApiController::class, 'updatePassword']);
    Route::post('/profile/request-seller', [ProfileApiController::class, 'requestSellerRole']);

    Route::post('/seller-applications',       [SellerApplicationController::class, 'store']);
    Route::get('/seller-applications/status', [SellerApplicationController::class, 'status']);

    Route::prefix('cart')->group(function () {
        Route::get('/',        [CartController::class, 'index']);
        Route::post('/',       [CartController::class, 'store']);
        Route::put('/{id}',    [CartController::class, 'update']);
        Route::delete('/',     [CartController::class, 'clear']);
        Route::delete('/{id}', [CartController::class, 'destroy']);
    });

    Route::prefix('favorites')->group(function () {
        Route::get('/',                  [FavoriteController::class, 'index']);
        Route::post('/',                 [FavoriteController::class, 'store']);
        Route::delete('/{productId}',    [FavoriteController::class, 'destroy']);
        Route::get('/check/{productId}', [FavoriteController::class, 'check']);
    });

    Route::post('/checkout',         [CheckoutController::class, 'store']);
    Route::post('/checkout/buy-now', [CheckoutController::class, 'buyNow']);

    // Client notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/',             [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/read-all',   [NotificationController::class, 'markAllRead']);
        Route::patch('/{id}/read',  [NotificationController::class, 'markRead']);
    });

    /*
    |----------------------------------------------------------------------
    | SELLER ROUTES
    |----------------------------------------------------------------------
    */
 /*
    |----------------------------------------------------------------------
    | SELLER ROUTES
    |----------------------------------------------------------------------
    */
    Route::prefix('seller')->group(function () {

        // ── Subscription (Phase 2) ────────────────────────────────────────
        Route::get('/subscription',          [\App\Http\Controllers\Api\Seller\SellerSubscriptionController::class, 'show']);
        Route::post('/subscription/upgrade', [\App\Http\Controllers\Api\Seller\SellerSubscriptionController::class, 'upgrade']);

        Route::get('/dashboard', [SellerDashboardController::class, 'index']);

        Route::get('/products/stats',   [SellerProductController::class, 'stats']);
        Route::get('/products',         [SellerProductController::class, 'index']);
        Route::post('/products',        [SellerProductController::class, 'store']);
        Route::get('/products/{id}',    [SellerProductController::class, 'show']);
        Route::put('/products/{id}',    [SellerProductController::class, 'update']);
        Route::post('/products/{id}',   [SellerProductController::class, 'update']);
        Route::delete('/products/{id}', [SellerProductController::class, 'destroy']);

        Route::delete('/products/{id}/images/{imageId}',        [SellerProductController::class, 'destroyImage']);
        Route::patch('/products/{id}/images/{imageId}/primary', [SellerProductController::class, 'setPrimaryImage']);

        Route::get('/products/{id}/update-requests', [SellerProductUpdateRequestController::class, 'index']);
        Route::post('/products/{id}/request-update', [SellerProductUpdateRequestController::class, 'store']);

        Route::get('/orders/stats',          [SellerOrderController::class, 'stats']);
        Route::get('/orders',                [SellerOrderController::class, 'index']);
        Route::get('/orders/{id}',           [SellerOrderController::class, 'show']);
        Route::patch('/orders/{id}/status',  [SellerOrderController::class, 'updateStatus']);
        Route::patch('/orders/{id}/payment', [SellerOrderController::class, 'updatePayment']);

        Route::get('/complaints/stats',          [SellerComplaintController::class, 'stats']);
        Route::get('/complaints',                [SellerComplaintController::class, 'index']);
        Route::get('/complaints/{id}',           [SellerComplaintController::class, 'show']);
        Route::patch('/complaints/{id}/note',    [SellerComplaintController::class, 'addNote']);
        Route::patch('/complaints/{id}/approve', [SellerComplaintController::class, 'approve']);
        Route::patch('/complaints/{id}/reject',  [SellerComplaintController::class, 'reject']);
    });

    /*
    |----------------------------------------------------------------------
    | CLIENT ROUTES
    |----------------------------------------------------------------------
    */
    Route::prefix('client')->group(function () {
        Route::get('/statistics',     [ClientOrderApiController::class, 'statistics']);
        Route::get('/orders',         [ClientOrderApiController::class, 'index']);
        Route::get('/orders/{order}', [ClientOrderApiController::class, 'show']);

        Route::get('/complaints/eligible-orders', [ClientComplaintController::class, 'eligibleOrders']);
        Route::get('/complaints',                 [ClientComplaintController::class, 'index']);
        Route::post('/complaints',                [ClientComplaintController::class, 'store']);
        Route::get('/complaints/{id}',            [ClientComplaintController::class, 'show']);
    });

    /*
    |----------------------------------------------------------------------
    | ADMIN ROUTES
    |----------------------------------------------------------------------
    */
    Route::prefix('admin')->middleware('role:admin')->group(function () {

        // ── Categories ────────────────────────────────────────────────────
        Route::get('/categories',               [AdminCategoryController::class, 'index']);
        Route::get('/categories/{id}',          [AdminCategoryController::class, 'show']);
        Route::post('/categories',              [AdminCategoryController::class, 'store']);
        Route::put('/categories/{id}',          [AdminCategoryController::class, 'update']);
        Route::patch('/categories/{id}/toggle', [AdminCategoryController::class, 'toggle']);
        Route::delete('/categories/{id}',       [AdminCategoryController::class, 'destroy']);

        // ── Subcategory ↔ Attribute assignment (BEFORE subcategory CRUD) ──
        Route::get('/subcategories/{id}/attributes',            [AdminAttributeController::class, 'subcategoryAttributes']);
        Route::post('/subcategories/{id}/attributes',           [AdminAttributeController::class, 'assignAttribute']);
        Route::put('/subcategories/{id}/attributes/{attrId}',   [AdminAttributeController::class, 'updateAssignment']);
        Route::delete('/subcategories/{id}/attributes/{attrId}',[AdminAttributeController::class, 'removeAttribute']);

        // ── Subcategories ─────────────────────────────────────────────────
        Route::get('/subcategories',       [AdminSubcategoryController::class, 'index']);
        Route::get('/subcategories/{id}',  [AdminSubcategoryController::class, 'show']);
        Route::post('/subcategories',      [AdminSubcategoryController::class, 'store']);
        Route::put('/subcategories/{id}',  [AdminSubcategoryController::class, 'update']);
        Route::delete('/subcategories/{id}',[AdminSubcategoryController::class, 'destroy']);

        // ── Global Attributes ─────────────────────────────────────────────
        Route::get('/attributes',                         [AdminAttributeController::class, 'index']);
        Route::post('/attributes',                        [AdminAttributeController::class, 'store']);
        Route::put('/attributes/{id}',                    [AdminAttributeController::class, 'update']);
        Route::delete('/attributes/{id}',                 [AdminAttributeController::class, 'destroy']);
        Route::post('/attributes/{id}/options',           [AdminAttributeController::class, 'addOption']);
        Route::put('/attributes/{id}/options/{optId}',    [AdminAttributeController::class, 'updateOption']);
        Route::delete('/attributes/{id}/options/{optId}', [AdminAttributeController::class, 'deleteOption']);

        // ── Users ─────────────────────────────────────────────────────────
        Route::get('/users',              [AdminUserController::class, 'index']);
        Route::get('/users/{id}',         [AdminUserController::class, 'show']);
        Route::put('/users/{id}',         [AdminUserController::class, 'update']);
        Route::patch('/users/{id}/ban',   [AdminUserController::class, 'ban']);
        Route::patch('/users/{id}/unban', [AdminUserController::class, 'unban']);
        Route::delete('/users/{id}',      [AdminUserController::class, 'destroy']);

        // ── Sellers ───────────────────────────────────────────────────────
        Route::get('/sellers',                [SellerController::class, 'index']);
        Route::get('/sellers/{id}',           [SellerController::class, 'show']);
        Route::put('/sellers/{id}',           [SellerController::class, 'update']);
        Route::delete('/sellers/{id}',        [SellerController::class, 'destroy']);
        Route::patch('/sellers/{id}/role',    [SellerController::class, 'changeRole']);
        Route::patch('/sellers/{id}/approve', [SellerController::class, 'approve']);
        Route::patch('/sellers/{id}/reject',  [SellerController::class, 'reject']);
        Route::patch('/sellers/{id}/suspend', [SellerController::class, 'suspend']);

        // ── Seller Applications ───────────────────────────────────────────
        Route::get('/seller-applications',                        [SellerApplicationController::class, 'index']);
        Route::get('/seller-applications/{id}',                   [SellerApplicationController::class, 'show']);
        Route::post('/seller-applications/{application}/approve', [SellerApplicationController::class, 'approve']);
        Route::post('/seller-applications/{application}/reject',  [SellerApplicationController::class, 'reject']);
        
        // ── Products ──────────────────────────────────────────────────────
        Route::get('/products',                [AdminProductController::class, 'index']);
        Route::get('/products/{id}',           [AdminProductController::class, 'show']);
        Route::put('/products/{id}',           [AdminProductController::class, 'update']);
        Route::patch('/products/{id}/approve', [AdminProductController::class, 'approve']);
        Route::patch('/products/{id}/reject',  [AdminProductController::class, 'reject']);
        Route::patch('/products/{id}/disable', [AdminProductController::class, 'disable']);
        Route::delete('/products/{id}',        [AdminProductController::class, 'destroy']);

        // ── Product Update Requests ───────────────────────────────────────
        Route::get('/product-update-requests/stats',         [AdminProductUpdateRequestController::class, 'stats']);
        Route::get('/product-update-requests',               [AdminProductUpdateRequestController::class, 'index']);
        Route::get('/product-update-requests/{id}',          [AdminProductUpdateRequestController::class, 'show']);
        Route::post('/product-update-requests/{id}/approve', [AdminProductUpdateRequestController::class, 'approve']);
        Route::post('/product-update-requests/{id}/reject',  [AdminProductUpdateRequestController::class, 'reject']);

        // ── Orders ────────────────────────────────────────────────────────
        Route::get('/orders/stats',         [AdminOrderController::class, 'stats']);
        Route::get('/orders',               [AdminOrderController::class, 'index']);
        Route::get('/orders/{id}',          [AdminOrderController::class, 'show']);
        Route::patch('/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);

        // ── Admin Notifications ───────────────────────────────────────────
        Route::prefix('notifications')->group(function () {
            Route::get('/',             [AdminNotificationController::class, 'index']);
            Route::get('/unread-count', [AdminNotificationController::class, 'unreadCount']);
            Route::patch('/read-all',   [AdminNotificationController::class, 'markAllRead']);
            Route::patch('/{id}/read',  [AdminNotificationController::class, 'markRead']);
        });

        // ── Admin Complaints ──────────────────────────────────────────────
        Route::get('/complaints/stats',                    [AdminComplaintController::class, 'stats']);
        Route::get('/complaints',                          [AdminComplaintController::class, 'index']);
        Route::get('/complaints/{id}',                     [AdminComplaintController::class, 'show']);
        Route::patch('/complaints/{id}/approve',           [AdminComplaintController::class, 'approve']);
        Route::patch('/complaints/{id}/reject',            [AdminComplaintController::class, 'reject']);
        Route::patch('/complaints/{id}/confirm-rejection', [AdminComplaintController::class, 'confirmRejection']);
        Route::patch('/complaints/{id}/override-approve',  [AdminComplaintController::class, 'overrideToApproved']);

        Route::patch('/orders/{id}/confirm-payment',  [\App\Http\Controllers\Admin\OrderController::class, 'confirmPayment']);
        Route::patch('/users/{id}/wallet/top-up',     [\App\Http\Controllers\Admin\UserController::class, 'walletTopUp']);

    }); // ← admin group ends HERE
    // ── Address Book ──────────────────────────────────────────────────────────
Route::prefix('addresses')->group(function () {
    Route::get('/',             [AddressController::class, 'index']);
    Route::post('/',            [AddressController::class, 'store']);
    Route::put('/{id}',         [AddressController::class, 'update']);
    Route::delete('/{id}',      [AddressController::class, 'destroy']);
    Route::patch('/{id}/default',[AddressController::class, 'setDefault']);
});
Route::get('/wallet/balance',                   [\App\Http\Controllers\Api\Client\PaymentController::class, 'walletBalance']);
Route::get('/wallet/transactions',              [\App\Http\Controllers\Api\Client\PaymentController::class, 'walletTransactions']);
Route::post('/payment/stripe/create-intent',    [\App\Http\Controllers\Api\Client\PaymentController::class, 'createStripeIntent']);
// AI proxy — Red/Black Pepper only
Route::middleware('auth:sanctum')->post('/ai/groq', [AIController::class, 'proxy']);
}); // ← auth:sanctum group ends HERE