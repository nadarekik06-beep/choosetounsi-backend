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
use App\Http\Controllers\Api\Seller\RestockController;
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
use App\Http\Controllers\Admin\AdminAttributeController;
use App\Http\Controllers\Api\Client\AddressController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\Seller\SellerSubscriptionController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\Api\Seller\SellerAnalyticsController;
use App\Http\Controllers\Api\Seller\SellerAIController;
use App\Http\Controllers\Api\Seller\BlackPepperController;
use App\Http\Controllers\Admin\AdminVipRequestController;
use App\Http\Controllers\Api\Seller\SponsorshipController;
use App\Http\Controllers\Admin\AdminSponsorshipController;
use App\Http\Controllers\Api\Delivery\DeliveryController;
use App\Http\Controllers\Api\BrandProductController as PublicBrandProductController;
use App\Http\Controllers\Admin\BrandProductController;
use App\Http\Controllers\Api\UserPreferenceController;
use App\Http\Controllers\Api\ProductRecommendationController;
use App\Http\Controllers\Api\Seller\SellerPackController;
use App\Http\Controllers\Api\PublicPackController;
use App\Http\Controllers\Api\Seller\SellerPromotionController;
use App\Http\Controllers\Api\PublicPromotionController;
use App\Http\Controllers\Api\Seller\CommissionController;
use App\Http\Controllers\Api\Delivery\DeliveryAuthController;
use App\Http\Controllers\Api\Seller\SellerForecastController;
use App\Http\Controllers\Api\ProductReviewController;
use App\Http\Controllers\Api\Client\ReviewController as ClientReviewController;
use App\Http\Controllers\Api\Seller\SellerReviewController;
use App\Http\Controllers\Admin\AdminReviewController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\SettlementController;
use App\Http\Controllers\Api\Seller\EarningsController;
/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

Route::post('/auth/login',    [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback']);
Route::post('/auth/verify-email',        [AuthController::class, 'verifyEmail']);
Route::post('/auth/resend-verification', [AuthController::class, 'resendVerification']);

Route::get('/products/{slug}/reviews', [ProductReviewController::class, 'index']);

Route::post('/products/by-ids', [ProductController::class, 'byIds']);
Route::get('/products',          [ProductController::class, 'index']);
Route::get('/products/featured', [ProductController::class, 'featured']);

// ── Recommendation routes MUST come BEFORE /products/{slug} ──────────────────
Route::get('/products/{slug}/similar',       [ProductRecommendationController::class, 'similar']);
Route::get('/products/{slug}/complementary', [ProductRecommendationController::class, 'complementary']);
Route::get('/products/{slug}/from-seller',   [ProductRecommendationController::class, 'fromSeller']);
Route::get('/products/{slug}/recommended',   [ProductRecommendationController::class, 'recommended']);

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

Route::get('/brand-products',          [PublicBrandProductController::class, 'index']);
Route::get('/brand-products/featured', [PublicBrandProductController::class, 'featured']);
Route::get('/brand-products/{slug}',   [PublicBrandProductController::class, 'show']);
Route::get('/recommendations',                    [ProductRecommendationController::class, 'feed']);
Route::get('/recommendations/similar/{productId}', [ProductRecommendationController::class, 'similar']);

// This must come BEFORE the auth:sanctum group
Route::post(
    '/payment/stripe/webhook',
    [\App\Http\Controllers\Api\Client\PaymentController::class, 'stripeWebhook']
)->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/sponsored-products', [SponsorshipController::class, 'publicFeed']);
Route::post('/sponsorships/{id}/impression', [SponsorshipController::class, 'recordImpression']);
Route::post('/sponsorships/{id}/click', [SponsorshipController::class, 'recordClick']);
Route::get('/packs',        [PublicPackController::class, 'index']);
Route::get('/packs/{slug}', [PublicPackController::class, 'show']);
Route::get('/flash-sales', [PublicPromotionController::class, 'flashSales']);
Route::get('/discounts', [PublicPromotionController::class, 'discounts']);
Route::get('/promotions/product/{productId}', [PublicPromotionController::class, 'forProduct']);
Route::post('/delivery/register', [DeliveryAuthController::class, 'register']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password',  [AuthController::class, 'resetPassword']);

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

    Route::post('/seller-applications',          [SellerApplicationController::class, 'store']);
    Route::get('/seller-applications/status',    [SellerApplicationController::class, 'status']);
    Route::get('/seller-applications/mine',      [SellerApplicationController::class, 'mine']);   // ← NEW
    Route::put('/seller-applications/{id}',      [SellerApplicationController::class, 'update']); // ← NEW

    // ── User preferences & onboarding ─────────────────────────────────────────
    Route::prefix('preferences')->group(function () {
        Route::get('/onboarding-data', [UserPreferenceController::class, 'onboardingData']);
        Route::get('/',                [UserPreferenceController::class, 'show']);
        Route::post('/',               [UserPreferenceController::class, 'store']);
        Route::post('/skip',           [UserPreferenceController::class, 'skip']);
        Route::put('/',                [UserPreferenceController::class, 'update']);
    });

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
    Route::prefix('seller')->group(function () {

        // ── Subscription ──────────────────────────────────────────────────
        Route::get('/subscription',          [\App\Http\Controllers\Api\Seller\SellerSubscriptionController::class, 'show']);
        Route::post('/subscription/upgrade', [\App\Http\Controllers\Api\Seller\SellerSubscriptionController::class, 'upgrade']);
        //----─ Commission Calculation (for frontend preview) ─────────────────────────
        Route::post('/commission/calculate', [CommissionController::class, 'calculate']);

        // ── Advanced Analytics (Red Pepper +) ─────────────────────────────
        Route::prefix('analytics')
            ->middleware('seller.plan:red')
            ->group(function () {
                Route::get('/overview',  [SellerAnalyticsController::class, 'overview']);
                Route::get('/products',  [SellerAnalyticsController::class, 'products']);
                Route::get('/customers', [SellerAnalyticsController::class, 'customers']);
                Route::get('/heatmap',   [SellerAnalyticsController::class, 'heatmap']);
                Route::post('/forecast',          [SellerForecastController::class, 'fullForecast']);
                Route::get('/forecast/regional',  [SellerForecastController::class, 'regionalDemand']);
                Route::get('/forecast/similar',   [SellerForecastController::class, 'similarProducts']);
                Route::get('/forecast/events',    [SellerForecastController::class, 'upcomingEvents']);
                Route::post('/forecast/explain',  [SellerForecastController::class, 'aiExplain']);
                Route::delete('/forecast/cache',   [SellerForecastController::class, 'invalidateCache']);
                Route::get('/forecast/cache-age',  [SellerForecastController::class, 'cacheAge']);
            });

        // ── AI Business Tools (Red Pepper +) ──────────────────────────────
        Route::prefix('ai')
            ->middleware('seller.plan:red')
            ->group(function () {
                Route::post('/price-optimizer',       [SellerAIController::class, 'priceOptimizer']);
                Route::post('/sales-predictor',       [SellerAIController::class, 'salesPredictor']);
                Route::post('/description-generator', [SellerAIController::class, 'descriptionGenerator']);
                Route::post('/quick-description', [SellerAIController::class, 'quickDescription']);
                Route::post('/recommender',           [SellerAIController::class, 'recommender']);
            });

        // ── Black Pepper ───────────────────────────────────────────────────
        Route::prefix('black')
            ->middleware('seller.plan:black')
            ->group(function () {
                Route::get('/ai-hub',          [BlackPepperController::class, 'aiHub']);
                Route::get('/profit-center',   [BlackPepperController::class, 'profitCenter']);
                Route::get('/sponsored',                   [BlackPepperController::class, 'sponsoredProducts']);
                Route::post('/sponsor/{id}',               [BlackPepperController::class, 'toggleSponsorship']);
                Route::get('/vip-requests',    [BlackPepperController::class, 'myVipRequests']);
                Route::post('/vip-request',    [BlackPepperController::class, 'submitVipRequest']);
                Route::get('/daily-brief', [BlackPepperController::class, 'dailyBrief']);
                Route::get('/funnel-insights', [BlackPepperController::class, 'funnelInsights']);
                Route::get('/quality-audit',   [BlackPepperController::class, 'qualityAudit']);
                Route::get('/auto-promote-suggestions', [BlackPepperController::class, 'autoPromote']);
                });

        Route::get('/dashboard', [SellerDashboardController::class, 'index']);

        // ── Products ──────────────────────────────────────────────────────
        Route::get('/products/stats',   [SellerProductController::class, 'stats']);
        Route::get('/products',         [SellerProductController::class, 'index']);
        Route::post('/products',        [SellerProductController::class, 'store']);
        Route::get('/products/{id}',    [SellerProductController::class, 'show']);
        Route::put('/products/{id}',    [SellerProductController::class, 'update']);
        Route::post('/products/{id}',   [SellerProductController::class, 'update']);
        Route::delete('/products/{id}', [SellerProductController::class, 'destroy']);

        Route::post('/products/{id}/restock', [RestockController::class, 'restock']);

        Route::delete('/products/{id}/images/{imageId}',        [SellerProductController::class, 'destroyImage']);
        Route::patch('/products/{id}/images/{imageId}/primary', [SellerProductController::class, 'setPrimaryImage']);

        Route::get('/products/{id}/update-requests', [SellerProductUpdateRequestController::class, 'index']);
        Route::post('/products/{id}/request-update', [SellerProductUpdateRequestController::class, 'store']);

        // ── Orders ────────────────────────────────────────────────────────
        Route::get('/orders/stats',          [SellerOrderController::class, 'stats']);
        Route::get('/orders',                [SellerOrderController::class, 'index']);
        Route::get('/orders/{id}',           [SellerOrderController::class, 'show']);
        Route::patch('/orders/{id}/status',  [SellerOrderController::class, 'updateStatus']);
        Route::patch('/orders/{id}/payment', [SellerOrderController::class, 'updatePayment']);
        Route::get('orders/{id}/invoice', [\App\Http\Controllers\Api\Seller\SellerInvoiceController::class, 'show']);

        // ── Complaints ────────────────────────────────────────────────────
        Route::get('/complaints/stats',          [SellerComplaintController::class, 'stats']);
        Route::get('/complaints',                [SellerComplaintController::class, 'index']);
        Route::get('/complaints/{id}',           [SellerComplaintController::class, 'show']);
        Route::patch('/complaints/{id}/note',    [SellerComplaintController::class, 'addNote']);
        Route::patch('/complaints/{id}/approve', [SellerComplaintController::class, 'approve']);
        Route::patch('/complaints/{id}/reject',  [SellerComplaintController::class, 'reject']);

        // ── Sponsorships ──────────────────────────────────────────────────
        Route::prefix('sponsorships')->group(function () {
            Route::get('/quota',          [SponsorshipController::class, 'quota']);
            Route::get('/',               [SponsorshipController::class, 'index']);
            Route::post('/sponsor',       [SponsorshipController::class, 'sponsor']);
            Route::delete('/{id}/cancel', [SponsorshipController::class, 'cancel']);
        });

        // ── Packs ─────────────────────────────────────────────────────────
        Route::get('/packs/stats',    [SellerPackController::class, 'stats']);
        Route::get('/packs/products', [SellerPackController::class, 'sellerProducts']);
        Route::get('/packs',          [SellerPackController::class, 'index']);
        Route::post('/packs',         [SellerPackController::class, 'store']);
        Route::get('/packs/{id}',     [SellerPackController::class, 'show']);
        Route::put('/packs/{id}',     [SellerPackController::class, 'update']);
        Route::post('/packs/{id}',    [SellerPackController::class, 'update']);
        Route::delete('/packs/{id}',  [SellerPackController::class, 'destroy']);

        // ── Promotions ────────────────────────────────────────────────────
        // stats MUST come before {id} to avoid being matched as an ID
        Route::get('/promotions/stats',    [SellerPromotionController::class, 'stats']);
        Route::get('/promotions',          [SellerPromotionController::class, 'index']);
        Route::post('/promotions',         [SellerPromotionController::class, 'store']);
        Route::get('/promotions/{id}',     [SellerPromotionController::class, 'show']);
        Route::put('/promotions/{id}',     [SellerPromotionController::class, 'update']);
        Route::delete('/promotions/{id}',  [SellerPromotionController::class, 'destroy']);


                
        Route::get('/reviews/stats',              [SellerReviewController::class, 'stats']);
        Route::get('/reviews',                    [SellerReviewController::class, 'index']);
        Route::post('/reviews/{id}/reply',        [SellerReviewController::class, 'reply']);
        Route::delete('/reviews/{id}/reply',      [SellerReviewController::class, 'deleteReply']);
        Route::post('/reviews/{id}/report',       [SellerReviewController::class, 'report']);

        Route::prefix('earnings')->group(function () {
        Route::get('overview', [EarningsController::class, 'overview']);
        Route::get('orders',   [EarningsController::class, 'orders']);
        Route::get('history',  [EarningsController::class, 'history']);
    });

    }); // ← seller group ends HERE
    Route::post('/reviews/{id}/vote',   [ClientReviewController::class, 'vote']);
    Route::post('/reviews/{id}/report', [ClientReviewController::class, 'report']);
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
        
        Route::get('/reviews/eligible',            [ClientReviewController::class, 'eligible']);
        Route::get('/reviews/tags',                [ClientReviewController::class, 'tags']);
        Route::post('/reviews',                    [ClientReviewController::class, 'store']);
        Route::get('/reviews/prompts',             [ClientReviewController::class, 'pendingPrompts']);
        Route::post('/reviews/prompts/{id}/dismiss',[ClientReviewController::class, 'dismissPrompt']);
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
        Route::get('/subcategories',        [AdminSubcategoryController::class, 'index']);
        Route::get('/subcategories/{id}',   [AdminSubcategoryController::class, 'show']);
        Route::post('/subcategories',       [AdminSubcategoryController::class, 'store']);
        Route::put('/subcategories/{id}',   [AdminSubcategoryController::class, 'update']);
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

        Route::get('/brand-products/stats',                           [\App\Http\Controllers\Admin\BrandProductController::class, 'stats']);
        Route::get('/brand-products',                                 [\App\Http\Controllers\Admin\BrandProductController::class, 'index']);
        Route::post('/brand-products',                                [\App\Http\Controllers\Admin\BrandProductController::class, 'store']);
        Route::get('/brand-products/{id}',                            [\App\Http\Controllers\Admin\BrandProductController::class, 'show']);
        Route::put('/brand-products/{id}',                            [\App\Http\Controllers\Admin\BrandProductController::class, 'update']);
        Route::post('/brand-products/{id}',                           [\App\Http\Controllers\Admin\BrandProductController::class, 'update']);
        Route::delete('/brand-products/{id}',                         [\App\Http\Controllers\Admin\BrandProductController::class, 'destroy']);
        Route::delete('/brand-products/{id}/images/{imageId}',        [\App\Http\Controllers\Admin\BrandProductController::class, 'destroyImage']);
        Route::patch('/brand-products/{id}/images/{imageId}/primary', [\App\Http\Controllers\Admin\BrandProductController::class, 'setPrimaryImage']);

        // ── Product Update Requests ───────────────────────────────────────
        Route::get('/product-update-requests/stats',         [AdminProductUpdateRequestController::class, 'stats']);
        Route::get('/product-update-requests',               [AdminProductUpdateRequestController::class, 'index']);
        Route::get('/product-update-requests/{id}',          [AdminProductUpdateRequestController::class, 'show']);
        Route::post('/product-update-requests/{id}/approve', [AdminProductUpdateRequestController::class, 'approve']);
        Route::post('/product-update-requests/{id}/reject',  [AdminProductUpdateRequestController::class, 'reject']);

        // ── Orders ────────────────────────────────────────────────────────
        Route::get('/orders/stats',                [AdminOrderController::class, 'stats']);
        Route::get('/orders',                      [AdminOrderController::class, 'index']);
        Route::get('/orders/{id}',                 [AdminOrderController::class, 'show']);
        Route::patch('/orders/{id}/status',        [AdminOrderController::class, 'updateStatus']);
        Route::patch('/orders/{id}/payment-status',[AdminOrderController::class, 'updatePaymentStatus']);
        Route::patch('/orders/{id}/confirm-payment',  [\App\Http\Controllers\Admin\OrderController::class, 'confirmPayment']);

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

        Route::patch('/users/{id}/wallet/top-up', [\App\Http\Controllers\Admin\UserController::class, 'walletTopUp']);

        // ── VIP Requests ──────────────────────────────────────────────────
        Route::get('/vip-requests/stats',            [AdminVipRequestController::class, 'stats']);
        Route::get('/vip-requests',                  [AdminVipRequestController::class, 'index']);
        Route::get('/vip-requests/{id}',             [AdminVipRequestController::class, 'show']);
        Route::patch('/vip-requests/{id}/approve',   [AdminVipRequestController::class, 'approve']);
        Route::patch('/vip-requests/{id}/complete',  [AdminVipRequestController::class, 'complete']);
        Route::patch('/vip-requests/{id}/reject',    [AdminVipRequestController::class, 'reject']);
        Route::patch('/vip-requests/{id}/note',      [AdminVipRequestController::class, 'addNote']);

        // ── Sponsorships ──────────────────────────────────────────────────
        Route::get('/sponsorships/stats',         [AdminSponsorshipController::class, 'stats']);
        Route::get('/sponsorships',               [AdminSponsorshipController::class, 'index']);
        Route::patch('/sponsorships/{id}/cancel', [AdminSponsorshipController::class, 'cancel']);
        Route::patch('/sponsorships/{id}/boost',  [AdminSponsorshipController::class, 'boost']);



        Route::get('/reviews/stats',                     [AdminReviewController::class, 'stats']);
        Route::get('/reviews',                           [AdminReviewController::class, 'index']);
        Route::patch('/reviews/{id}/approve',            [AdminReviewController::class, 'approve']);
        Route::patch('/reviews/{id}/reject',             [AdminReviewController::class, 'reject']);
        Route::patch('/reviews/{id}/flag',               [AdminReviewController::class, 'flag']);
        Route::delete('/reviews/{id}',                   [AdminReviewController::class, 'destroy']);
        Route::delete('/review-media/{id}',              [AdminReviewController::class, 'destroyMedia']);
        Route::patch('/review-media/{id}/hide',          [AdminReviewController::class, 'hideMedia']);
        Route::get('/review-reports',                    [AdminReviewController::class, 'reports']);
        Route::patch('/review-reports/{id}/resolve',     [AdminReviewController::class, 'resolveReport']);
        Route::delete('/review-replies/{id}',            [AdminReviewController::class, 'destroyReply']);


        Route::prefix('finance')->group(function () {
        Route::get('overview',         [FinanceController::class, 'overview']);
        Route::get('orders',           [FinanceController::class, 'orders']);
        Route::get('sellers',          [FinanceController::class, 'sellers']);
        Route::get('pending-payouts',  [FinanceController::class, 'pendingPayouts']);
        Route::post('confirm-money/{id}', [FinanceController::class, 'confirmMoneyReceived']);
    });

    // Settlement batches
    Route::prefix('settlements')->group(function () {
        Route::get('/',            [SettlementController::class, 'index']);
        Route::post('create',      [SettlementController::class, 'create']);
        Route::get('{id}',         [SettlementController::class, 'show']);
        Route::post('{id}/confirm',[SettlementController::class, 'confirm']);
        Route::post('{id}/cancel', [SettlementController::class, 'cancel']);
    });

    }); // ← admin group ends HERE

    // ── Address Book ──────────────────────────────────────────────────────────
    Route::prefix('addresses')->group(function () {
        Route::get('/',              [AddressController::class, 'index']);
        Route::post('/',             [AddressController::class, 'store']);
        Route::put('/{id}',          [AddressController::class, 'update']);
        Route::delete('/{id}',       [AddressController::class, 'destroy']);
        Route::patch('/{id}/default',[AddressController::class, 'setDefault']);
    });

    Route::get('/wallet/balance',                [\App\Http\Controllers\Api\Client\PaymentController::class, 'walletBalance']);
    Route::get('/wallet/transactions',           [\App\Http\Controllers\Api\Client\PaymentController::class, 'walletTransactions']);
    Route::post('/payment/stripe/create-intent', [\App\Http\Controllers\Api\Client\PaymentController::class, 'createStripeIntent']);

    // AI proxy — Red/Black Pepper only
    Route::middleware('auth:sanctum')->post('/ai/groq', [AIController::class, 'proxy']);

}); // ← auth:sanctum group ends HERE

/*
|--------------------------------------------------------------------------
| DELIVERY ROUTES — auth handled by DeliveryMiddleware (not sanctum group)
|--------------------------------------------------------------------------
*/
Route::prefix('delivery')
    ->middleware(['auth:sanctum', 'delivery'])
    ->group(function () {

        // ── Delivery Admin only ───────────────────────────────────────────
        Route::middleware('delivery:admin')->group(function () {
            Route::get('/stats',               [DeliveryController::class, 'stats']);
            Route::get('/orders',              [DeliveryController::class, 'readyOrders']);
            Route::get('/orders/active',       [DeliveryController::class, 'activeOrders']); // BEFORE /{id}
            Route::post('/orders/{id}/assign', [DeliveryController::class, 'assign']);
            Route::get('/team',                [DeliveryController::class, 'team']);
        });

        // ── Delivery Guy only ─────────────────────────────────────────────
        Route::middleware('delivery:guy')->group(function () {
            Route::get('/my-orders',           [DeliveryController::class, 'myOrders']);
            Route::put('/orders/{id}/status',  [DeliveryController::class, 'updateStatus']);
        });

        // ── Shared — LAST so /active is not swallowed by /{id} ───────────
        Route::get('/orders/{id}',             [DeliveryController::class, 'showOrder']);
    });