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
use App\Http\Controllers\Api\Seller\SellerStoreProfileController;
use App\Http\Controllers\Api\Client\SellerFollowController;
use App\Http\Controllers\Api\Seller\SellerProductController;
use App\Http\Controllers\Api\Seller\SellerOrderController;
use App\Http\Controllers\Api\Seller\RestockController;
use App\Http\Controllers\Admin\SellerController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\AdminSubscriptionController;
use App\Http\Controllers\Admin\AdminPlanController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
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
use App\Http\Controllers\Api\Seller\SellerDescriptionController;
use App\Http\Controllers\Api\Seller\BlackPepperController;
use App\Http\Controllers\Admin\AdminVipRequestController;
use App\Http\Controllers\Api\Seller\SponsorshipController;
use App\Http\Controllers\Api\Delivery\DeliveryController;
use App\Http\Controllers\Api\BrandProductController as PublicBrandProductController;
use App\Http\Controllers\Admin\BrandProductController;
use App\Http\Controllers\Api\UserPreferenceController;
use App\Http\Controllers\Api\ProductRecommendationController;
use App\Http\Controllers\Api\Seller\SellerPackController;
use App\Http\Controllers\Api\PublicPackController;
use App\Http\Controllers\Api\Seller\SellerPromotionController;
use App\Http\Controllers\Api\Seller\SellerCouponController;
use App\Http\Controllers\Api\PublicPromotionController;
use App\Http\Controllers\Api\PublicSellerController;
use App\Http\Controllers\Api\Seller\CommissionController;
use App\Http\Controllers\Api\Delivery\DeliveryAuthController;
use App\Http\Controllers\Api\Seller\SalesForecastController;
use App\Http\Controllers\Api\Seller\GrowthRadarController;
use App\Http\Controllers\Api\ProductReviewController;
use App\Http\Controllers\Api\Client\ReviewController as ClientReviewController;
use App\Http\Controllers\Api\Seller\SellerReviewController;
use App\Http\Controllers\Admin\AdminReviewController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\SettlementController;
use App\Http\Controllers\Admin\AdminPackController;
use App\Http\Controllers\Api\Seller\EarningsController;
use App\Http\Controllers\Api\Delivery\RefundDeliveryController;
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

Route::get('/product-occasions', [ProductController::class, 'occasions']);
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
Route::post('/ai/chat', [\App\Http\Controllers\Api\AiChatController::class, 'handle'])
    ->middleware('throttle:20,1');
Route::get('/seller-plans',          [\App\Http\Controllers\Api\PlatformInfoController::class, 'sellerPlans']);
Route::get('/seller-landing',        [\App\Http\Controllers\Api\PlatformInfoController::class, 'sellerLanding']);
Route::get('/checkout/payment-info', [\App\Http\Controllers\Api\PlatformInfoController::class, 'paymentInfo']);

Route::post('/search/text',  [\App\Http\Controllers\Api\SearchController::class, 'searchText']);
Route::post('/search/image', [\App\Http\Controllers\Api\SearchController::class, 'searchImage'])->middleware('throttle:image-search');
Route::get('/search/image/status',  [\App\Http\Controllers\Api\SearchController::class, 'imageStatus']);
Route::post('/search/image/click',  [\App\Http\Controllers\Api\SearchController::class, 'imageClick'])->middleware('throttle:30,1');
Route::get('/search/suggestions', [\App\Http\Controllers\Api\SearchController::class, 'suggestions']);
Route::get('/site-features',           [\App\Http\Controllers\Api\SiteFeaturesController::class, 'show']);
Route::get('/brand-products',          [PublicBrandProductController::class, 'index']);
Route::get('/brand-products/featured', [PublicBrandProductController::class, 'featured']);
Route::get('/brand-products/{slug}',   [PublicBrandProductController::class, 'show']);
Route::get('/recommendations',                    [ProductRecommendationController::class, 'feed']);
Route::get('/recommendations/similar/{productId}', [ProductRecommendationController::class, 'similar']);

// ── Homepage personalization ─────────────────────────────────────────────────
Route::get('/home/feed', [\App\Http\Controllers\Api\HomeFeedController::class, 'index']);
Route::get('/shop/overview', [\App\Http\Controllers\Api\ShopOverviewController::class, 'index']);

// Signal tracking (fire-and-forget from the storefront)
Route::post('/track', [\App\Http\Controllers\Api\TrackingController::class, 'store'])
    ->middleware('throttle:120,1');

// This must come BEFORE the auth:sanctum group
Route::post(
    '/payment/stripe/webhook',
    [\App\Http\Controllers\Api\Client\PaymentController::class, 'stripeWebhook']
)->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/sponsored-products', [SponsorshipController::class, 'publicFeed']);
// ── Ads (public) ──────────────────────────────────────────────────────────
Route::get('/ads/config', [\App\Http\Controllers\Api\AdsConfigController::class, 'show']);
Route::get('/ads', [\App\Http\Controllers\Api\AdsController::class, 'index'])->middleware('throttle:120,1');
Route::get('/ads/popup', [\App\Http\Controllers\Api\AdsController::class, 'popup'])->middleware('throttle:30,1');
Route::post('/ads/events', [\App\Http\Controllers\Api\AdsController::class, 'events'])->middleware('throttle:120,1');
Route::get('/ads/r/{token}', [\App\Http\Controllers\Api\AdsController::class, 'redirect'])
    ->where('token', '[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+')->middleware('throttle:60,1')->name('ads.redirect');
Route::post('/ads/top-ups/callback/{gateway}', [\App\Http\Controllers\Api\Seller\Ads\AdWalletController::class, 'callback'])
    ->middleware('throttle:60,1')->name('ads.top-ups.callback');
Route::post('/sponsorships/{id}/impression', [SponsorshipController::class, 'recordImpression']);
Route::post('/sponsorships/{id}/click', [SponsorshipController::class, 'recordClick']);
Route::get('/packs',        [PublicPackController::class, 'index']);
Route::get('/packs/{slug}', [PublicPackController::class, 'show']);
Route::get('/flash-sales', [PublicPromotionController::class, 'flashSales']);
Route::get('/discounts', [PublicPromotionController::class, 'discounts']);
Route::get('/promo-flyers', [PublicPromotionController::class, 'flyers'])->middleware('throttle:120,1');
Route::get('/deals',     [\App\Http\Controllers\Api\DealsController::class, 'index']);
Route::get('/promotions/product/{productId}', [PublicPromotionController::class, 'forProduct']);
Route::get('/sellers/{id}', [PublicSellerController::class, 'show']);
Route::post('/delivery/register', [DeliveryAuthController::class, 'register']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password',  [AuthController::class, 'resetPassword']);

/*
|--------------------------------------------------------------------------
| AUTHENTICATED ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // Marketing e-mail consent (opt-in)
    Route::get('/account/marketing-consent',  [\App\Http\Controllers\MarketingConsentController::class, 'show']);
    Route::post('/account/marketing-consent', [\App\Http\Controllers\MarketingConsentController::class, 'update']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/track/merge', [\App\Http\Controllers\Api\TrackingController::class, 'merge']);
    Route::get('/auth/user',    [AuthController::class, 'user']);
   
    Route::get('/profile',                 [ProfileApiController::class, 'show']);
    Route::match(['put', 'patch'], '/profile', [ProfileApiController::class, 'update'])->middleware('throttle:30,1');
    Route::put('/profile/password',        [ProfileApiController::class, 'updatePassword'])->middleware('throttle:6,1');
    Route::post('/profile/avatar',         [ProfileApiController::class, 'uploadAvatar'])->middleware('throttle:10,1');
    Route::delete('/profile/avatar',       [ProfileApiController::class, 'deleteAvatar'])->middleware('throttle:10,1');
    Route::post('/profile/email',          [ProfileApiController::class, 'requestEmailChange'])->middleware('throttle:5,1');
    Route::post('/profile/email/verify',   [ProfileApiController::class, 'confirmEmailChange'])->middleware('throttle:10,1');
    Route::put('/profile/notifications',   [ProfileApiController::class, 'updateNotifications']);
    Route::get('/profile/overview',        [\App\Http\Controllers\Api\Client\ProfileOverviewController::class, 'overview']);
    Route::get('/profile/reviews',         [\App\Http\Controllers\Api\Client\ProfileOverviewController::class, 'reviews']);
    Route::get('/profile/followed-sellers',[\App\Http\Controllers\Api\Client\ProfileOverviewController::class, 'followedSellers']);
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

    Route::post('/coupons/validate', [\App\Http\Controllers\Api\Client\CouponController::class, 'preview']);

    Route::prefix('favorites')->group(function () {
        Route::get('/',                  [FavoriteController::class, 'index']);
        Route::post('/',                 [FavoriteController::class, 'store']);
        Route::delete('/{productId}',    [FavoriteController::class, 'destroy']);
        Route::get('/check/{productId}', [FavoriteController::class, 'check']);
    });

    Route::prefix('seller-follows')->group(function () {
        Route::post('/{sellerId}',       [SellerFollowController::class, 'store']);
        Route::get('/check/{sellerId}',  [SellerFollowController::class, 'check']);
    });

    Route::middleware('profile.complete')->group(function () {
        Route::post('/checkout',         [CheckoutController::class, 'store']);
        Route::post('/checkout/buy-now', [CheckoutController::class, 'buyNow']);
    });

    // Client notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/',             [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/read-all',   [NotificationController::class, 'markAllRead']);
        Route::patch('/{id}/read',  [NotificationController::class, 'markRead']);
        Route::delete('/{id}',      [NotificationController::class, 'destroy']);
    });

    /*
    |----------------------------------------------------------------------
    | SELLER ROUTES
    |----------------------------------------------------------------------
    */
    Route::prefix('seller')->group(function () {
 // ── Subscription (full lifecycle) ─────────────────────────────────────
    Route::get('/subscription',                [\App\Http\Controllers\Api\Seller\SellerSubscriptionController::class, 'show']);
    Route::post('/subscription/upgrade',       [\App\Http\Controllers\Api\Seller\SellerSubscriptionController::class, 'upgrade']);
    Route::post('/subscription/downgrade',     [\App\Http\Controllers\Api\Seller\SellerSubscriptionController::class, 'downgrade']);    // NEW
    Route::delete('/subscription/downgrade',   [\App\Http\Controllers\Api\Seller\SellerSubscriptionController::class, 'cancelDowngrade']); // NEW
    Route::get('/subscription/history',        [\App\Http\Controllers\Api\Seller\SellerSubscriptionController::class, 'history']);

    // ── Manual payments via WhatsApp (ad-wallet top-ups, plan upgrades) ──
    Route::get('/payment-requests',              [\App\Http\Controllers\Api\Seller\SellerPaymentRequestController::class, 'index']);
    Route::get('/payment-requests/{id}',         [\App\Http\Controllers\Api\Seller\SellerPaymentRequestController::class, 'show'])->whereNumber('id');
    Route::post('/payment-requests/wallet-top-up', [\App\Http\Controllers\Api\Seller\SellerPaymentRequestController::class, 'topUp'])
        ->middleware(['seller.feature:sponsorships', 'throttle:10,1']);
    Route::post('/payment-requests/plan-upgrade', [\App\Http\Controllers\Api\Seller\SellerPaymentRequestController::class, 'planUpgrade'])->middleware('throttle:10,1');
    Route::post('/payment-requests/{id}/cancel', [\App\Http\Controllers\Api\Seller\SellerPaymentRequestController::class, 'cancel'])->whereNumber('id');
        //----─ Commission Calculation (for frontend preview) ─────────────────────────
        Route::post('/commission/calculate', [CommissionController::class, 'calculate']);
        Route::get('/shipping-cost',         [CommissionController::class, 'shippingCost']);

        // ── Advanced Analytics (Red Pepper +) ─────────────────────────────
        Route::prefix('analytics')
            ->middleware('seller.feature:analytics')
            ->group(function () {
                Route::get('/overview',  [SellerAnalyticsController::class, 'overview']);
                Route::get('/products',  [SellerAnalyticsController::class, 'products']);
                Route::get('/customers', [SellerAnalyticsController::class, 'customers']);
                Route::get('/heatmap',   [SellerAnalyticsController::class, 'heatmap']);
            });

        // ── Sales forecast (Outils IA → Ventes) — same plan feature as analytics ──
        Route::prefix('forecast')
            ->middleware('seller.feature:analytics')
            ->group(function () {
                Route::get('/',          [SalesForecastController::class, 'show']);
                Route::post('/refresh',  [SalesForecastController::class, 'refresh']);
                Route::get('/explain',   [SalesForecastController::class, 'explain'])->middleware('throttle:30,1');
                Route::put('/settings',  [SalesForecastController::class, 'updateSettings'])->middleware('throttle:20,1');
            });

        // ── AI product descriptions (every plan; tiers enforced by DescriptionPolicy) ──
        Route::prefix('ai')->group(function () {
            Route::get('/description/options', [SellerDescriptionController::class, 'options']);
            Route::post('/description',        [SellerDescriptionController::class, 'generate'])->middleware('throttle:8,1');
            Route::put('/description/voice',   [SellerDescriptionController::class, 'saveVoice'])->middleware('throttle:20,1');
            // Legacy alias used by older dashboard builds
            Route::post('/quick-description',  [SellerDescriptionController::class, 'generate'])->middleware('throttle:8,1');
        });

        // ── AI Business Tools (Red Pepper +) ──────────────────────────────
        Route::prefix('ai')
            ->middleware('seller.feature:ai_tools')
            ->group(function () {
                Route::post('/price-optimizer',       [SellerAIController::class, 'priceOptimizer']);
                Route::post('/recommender',           [SellerAIController::class, 'recommender']);
            });

        // ── Growth Radar (Black Pepper only: plan feature growth.full_feature = black_hub) ──
        Route::prefix('growth-radar')->middleware('seller.feature:black_hub')->group(function () {
            Route::get('/',                    [GrowthRadarController::class, 'index']);
            Route::get('/history',             [GrowthRadarController::class, 'history']);
            Route::post('/refresh',            [GrowthRadarController::class, 'refresh'])->middleware('throttle:6,1');
            Route::post('/cards/{id}/dismiss', [GrowthRadarController::class, 'dismiss'])->whereNumber('id');
            Route::post('/cards/{id}/snooze',  [GrowthRadarController::class, 'snooze'])->whereNumber('id');
            Route::post('/cards/{id}/applied', [GrowthRadarController::class, 'applied'])->whereNumber('id');
        });

        // ── Black Pepper ───────────────────────────────────────────────────
        Route::prefix('black')
            ->middleware('seller.feature:black_hub')
            ->group(function () {
                // Centre de profit: money, goals and profitability
                Route::get('/profit-center',          [\App\Http\Controllers\Api\Seller\ProfitCenterController::class, 'show']);
                Route::get('/revenue-goals',          [\App\Http\Controllers\Api\Seller\ProfitCenterController::class, 'show']); // legacy alias
                Route::post('/revenue-goals',         [\App\Http\Controllers\Api\Seller\ProfitCenterController::class, 'saveGoal'])->middleware('throttle:30,1');
                Route::delete('/revenue-goals/{month}', [\App\Http\Controllers\Api\Seller\ProfitCenterController::class, 'deleteGoal'])
                    ->where('month', '\d{4}-\d{2}')->middleware('throttle:30,1');
                Route::put('/profit-center/alerts',   [\App\Http\Controllers\Api\Seller\ProfitCenterController::class, 'updateAlerts'])->middleware('throttle:30,1');
                Route::get('/profit-center/export',   [\App\Http\Controllers\Api\Seller\ProfitCenterController::class, 'export'])->middleware('throttle:20,1');
                Route::get('/vip-requests',    [BlackPepperController::class, 'myVipRequests']);
                Route::post('/vip-request',    [BlackPepperController::class, 'submitVipRequest']);
                Route::get('/daily-brief', [BlackPepperController::class, 'dailyBrief']);
                // Analyse des visiteurs: behavioral funnel (replaces /funnel-insights)
                Route::get('/visitor-insights',          [\App\Http\Controllers\Api\Seller\VisitorInsightsController::class, 'show']);
                Route::post('/visitor-insights/actions', [\App\Http\Controllers\Api\Seller\VisitorInsightsController::class, 'applied'])->middleware('throttle:30,1');
                Route::get('/quality-audit',   [BlackPepperController::class, 'qualityAudit']);
                });

        Route::get('/dashboard', [SellerDashboardController::class, 'index']);

        // ── Store profile (branding) ─────────────────────────────────────────
        Route::get('/store-profile',              [SellerStoreProfileController::class, 'show']);
        Route::post('/store-profile/cover-photo', [SellerStoreProfileController::class, 'updateCoverPhoto']);
        Route::get('/pickup-address',             [SellerStoreProfileController::class, 'pickupAddress']);
        Route::put('/pickup-address',             [SellerStoreProfileController::class, 'updatePickupAddress']);

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
        Route::patch('/complaints/{id}/receive', [SellerComplaintController::class, 'receive']);

        // ── Ads: wallet, CPC campaigns, wizard tools ──────────────────────
        Route::prefix('ads')->middleware('seller.feature:sponsorships')->group(function () {
            Route::get('/config',                  [\App\Http\Controllers\Api\Seller\Ads\AdToolsController::class, 'config']);
            Route::post('/readiness',              [\App\Http\Controllers\Api\Seller\Ads\AdToolsController::class, 'readiness']);
            Route::post('/forecast',               [\App\Http\Controllers\Api\Seller\Ads\AdToolsController::class, 'forecast']);
            Route::get('/suggestions',             [\App\Http\Controllers\Api\Seller\Ads\AdToolsController::class, 'suggestions']);
            Route::get('/overview',                [\App\Http\Controllers\Api\Seller\Ads\AdCampaignController::class, 'overview']);

            Route::get('/wallet',                  [\App\Http\Controllers\Api\Seller\Ads\AdWalletController::class, 'show']);
            Route::get('/wallet/transactions',     [\App\Http\Controllers\Api\Seller\Ads\AdWalletController::class, 'transactions']);
            Route::get('/wallet/top-ups',          [\App\Http\Controllers\Api\Seller\Ads\AdWalletController::class, 'topUps']);
            Route::post('/wallet/top-up',          [\App\Http\Controllers\Api\Seller\Ads\AdWalletController::class, 'topUp'])->middleware('throttle:20,1');

            Route::get('/campaigns',               [\App\Http\Controllers\Api\Seller\Ads\AdCampaignController::class, 'index']);
            Route::post('/campaigns',              [\App\Http\Controllers\Api\Seller\Ads\AdCampaignController::class, 'store']);
            Route::get('/campaigns/{id}',          [\App\Http\Controllers\Api\Seller\Ads\AdCampaignController::class, 'show'])->whereNumber('id');
            Route::patch('/campaigns/{id}',        [\App\Http\Controllers\Api\Seller\Ads\AdCampaignController::class, 'update'])->whereNumber('id');
            Route::post('/campaigns/{id}/pause',   [\App\Http\Controllers\Api\Seller\Ads\AdCampaignController::class, 'pause'])->whereNumber('id');
            Route::post('/campaigns/{id}/resume',  [\App\Http\Controllers\Api\Seller\Ads\AdCampaignController::class, 'resume'])->whereNumber('id');
            Route::post('/campaigns/{id}/cancel',  [\App\Http\Controllers\Api\Seller\Ads\AdCampaignController::class, 'cancel'])->whereNumber('id');
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
        Route::post('/promotions',         [SellerPromotionController::class, 'store'])->middleware('seller.feature:promotions');
        Route::get('/promotions/{id}',     [SellerPromotionController::class, 'show']);
        Route::put('/promotions/{id}',     [SellerPromotionController::class, 'update'])->middleware('seller.feature:promotions');
        Route::delete('/promotions/{id}',  [SellerPromotionController::class, 'destroy']);

        // ── Coupons ───────────────────────────────────────────────────────
        Route::get('/coupons/stats',       [SellerCouponController::class, 'stats']);
        Route::get('/coupons',             [SellerCouponController::class, 'index']);
        Route::post('/coupons',            [SellerCouponController::class, 'store'])->middleware('seller.feature:coupons');
        Route::get('/coupons/{id}',        [SellerCouponController::class, 'show']);
        Route::put('/coupons/{id}',        [SellerCouponController::class, 'update'])->middleware('seller.feature:coupons');
        Route::delete('/coupons/{id}',     [SellerCouponController::class, 'destroy']);


                
        Route::get('/reviews/stats',              [SellerReviewController::class, 'stats']);
        Route::get('/reviews',                    [SellerReviewController::class, 'index']);
        Route::post('/reviews/{id}/reply',        [SellerReviewController::class, 'reply']);
        Route::delete('/reviews/{id}/reply',      [SellerReviewController::class, 'deleteReply']);
        Route::post('/reviews/{id}/report',       [SellerReviewController::class, 'report']);

        Route::prefix('earnings')->group(function () {
        Route::get('overview', [EarningsController::class, 'overview']);
        Route::get('orders',   [EarningsController::class, 'orders']);
        Route::get('orders/{id}/details', [EarningsController::class, 'orderDetails'])->whereNumber('id');
        Route::get('history',  [EarningsController::class, 'history']);
        Route::get('receipt',           [EarningsController::class, 'fullReceipt']);   // ← nouveau
        Route::get('settlement/{id}',   [EarningsController::class, 'settlementReceipt']); // ← nouveau
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
        Route::patch('/complaints/{id}/escalate', [ClientComplaintController::class, 'escalate']);
        Route::patch('/complaints/{id}/cancel',   [ClientComplaintController::class, 'cancel']);
        
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

        // ── Tunisian calendar (sales forecast markers & reminders) ─────────
        Route::get('/calendar-events',                 [\App\Http\Controllers\Admin\CalendarEventController::class, 'index']);
        Route::post('/calendar-events',                [\App\Http\Controllers\Admin\CalendarEventController::class, 'store']);
        Route::post('/calendar-events/generate-hijri', [\App\Http\Controllers\Admin\CalendarEventController::class, 'generateHijri']);
        Route::put('/calendar-events/{id}',            [\App\Http\Controllers\Admin\CalendarEventController::class, 'update'])->whereNumber('id');
        Route::delete('/calendar-events/{id}',         [\App\Http\Controllers\Admin\CalendarEventController::class, 'destroy'])->whereNumber('id');
        Route::post('/calendar-events/{id}/measure',   [\App\Http\Controllers\Admin\CalendarEventController::class, 'measure'])->whereNumber('id');

        // ── Homepage personalization debug ────────────────────────────────
        Route::get('/recommendations/debug', [\App\Http\Controllers\Admin\RecommendationDebugController::class, 'show']);

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
        Route::get('/products/{id}/review',    [AdminProductController::class, 'review']);
        Route::get('/products/{id}/editor',    [\App\Http\Controllers\Admin\ProductEditorController::class, 'show']);
        Route::post('/products/{id}/editor',   [\App\Http\Controllers\Admin\ProductEditorController::class, 'save']);
        Route::patch('/products/{id}/request-changes', [AdminProductController::class, 'requestChanges']);
        Route::patch('/products/{id}/featured',        [AdminProductController::class, 'toggleFeatured']);
        Route::put('/products/{id}',           [AdminProductController::class, 'update']);
        Route::patch('/products/{id}/approve', [AdminProductController::class, 'approve']);
        Route::patch('/products/{id}/reject',  [AdminProductController::class, 'reject']);
        Route::patch('/products/{id}/disable', [AdminProductController::class, 'disable']);
        Route::delete('/products/{id}',        [AdminProductController::class, 'destroy']);
        Route::post('/products/{id}/restore',  [AdminProductController::class, 'restore']);
        Route::delete('/products/{id}/force',  [AdminProductController::class, 'forceDestroy']);
        Route::get('/site-features',                                  [\App\Http\Controllers\Api\SiteFeaturesController::class, 'show']);
        Route::put('/site-features',                                  [\App\Http\Controllers\Api\SiteFeaturesController::class, 'update']);
        Route::get('/brand-products/stats',                           [\App\Http\Controllers\Admin\BrandProductController::class, 'stats']);
        Route::get('/brand-products',                                 [\App\Http\Controllers\Admin\BrandProductController::class, 'index']);
        Route::post('/brand-products',                                [\App\Http\Controllers\Admin\BrandProductController::class, 'store']);
        Route::get('/brand-products/{id}',                            [\App\Http\Controllers\Admin\BrandProductController::class, 'show']);
        Route::put('/brand-products/{id}',                            [\App\Http\Controllers\Admin\BrandProductController::class, 'update']);
        Route::post('/brand-products/{id}',                           [\App\Http\Controllers\Admin\BrandProductController::class, 'update']);
        Route::delete('/brand-products/{id}',                         [\App\Http\Controllers\Admin\BrandProductController::class, 'destroy']);
        Route::delete('/brand-products/{id}/images/{imageId}',        [\App\Http\Controllers\Admin\BrandProductController::class, 'destroyImage']);
        Route::patch('/brand-products/{id}/images/{imageId}/primary', [\App\Http\Controllers\Admin\BrandProductController::class, 'setPrimaryImage']);



        

        // ── Product changes (seller edits log; replaces update requests) ──
        Route::get('/product-changes/stats',        [\App\Http\Controllers\Admin\ProductChangeController::class, 'stats']);
        Route::get('/product-changes',              [\App\Http\Controllers\Admin\ProductChangeController::class, 'index']);
        Route::get('/product-changes/{id}',         [\App\Http\Controllers\Admin\ProductChangeController::class, 'show']);
        Route::post('/product-changes/{id}/revert', [\App\Http\Controllers\Admin\ProductChangeController::class, 'revert']);


        // ── Admin Packs ───────────────────────────────────────────────────
        // stats MUST come before /{id} to avoid being captured as an ID
        Route::get('/packs/stats',          [AdminPackController::class, 'stats']);
        Route::get('/packs',                [AdminPackController::class, 'index']);
        Route::get('/packs/{id}',           [AdminPackController::class, 'show']);
        Route::patch('/packs/{id}/approve', [AdminPackController::class, 'approve']);
        Route::patch('/packs/{id}/reject',  [AdminPackController::class, 'reject']);
        Route::patch('/packs/{id}/toggle',  [AdminPackController::class, 'toggle']);
        Route::delete('/packs/{id}',        [AdminPackController::class, 'destroy']);

        // ── Orders ────────────────────────────────────────────────────────
        Route::get('/orders/stats',                [AdminOrderController::class, 'stats']);
        Route::get('/orders',                      [AdminOrderController::class, 'index']);
        Route::get('/orders/{id}',                 [AdminOrderController::class, 'show']);
        Route::patch('/orders/{id}/status',        [AdminOrderController::class, 'updateStatus']);
        Route::patch('/orders/{id}/payment-status',[AdminOrderController::class, 'updatePaymentStatus']);
        Route::patch('/orders/{id}/confirm-payment',  [\App\Http\Controllers\Admin\OrderController::class, 'confirmPayment']);
        Route::patch('/orders/{id}/confirm-order',     [AdminOrderController::class, 'confirmOrder']);
        Route::patch('/orders/{id}/note',              [AdminOrderController::class, 'saveNote']);
        // Delivery documents (PDF) + seller pickup address fixes from the order drawer
        Route::post('/orders/export/slips',                         [\App\Http\Controllers\Admin\OrderExportController::class, 'bulk']);
        Route::get('/orders/{id}/export/slips',                     [\App\Http\Controllers\Admin\OrderExportController::class, 'slips'])->whereNumber('id');
        Route::get('/orders/{id}/export/slips/{sellerOrderId}',     [\App\Http\Controllers\Admin\OrderExportController::class, 'slip'])->whereNumber(['id', 'sellerOrderId']);
        Route::get('/orders/{id}/export/summary',                   [\App\Http\Controllers\Admin\OrderExportController::class, 'summary'])->whereNumber('id');
        Route::put('/sellers/{sellerId}/pickup-address',            [AdminOrderController::class, 'updateSellerPickup'])->whereNumber('sellerId');

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
        Route::patch('/complaints/{id}/schedule-pickup',   [AdminComplaintController::class, 'schedulePickup']);
        Route::patch('/complaints/{id}/picked-up',         [AdminComplaintController::class, 'pickedUp']);
        Route::patch('/complaints/{id}/receive',           [AdminComplaintController::class, 'receive']);
        Route::patch('/complaints/{id}/refund',            [AdminComplaintController::class, 'refund']);
        Route::patch('/complaints/{id}/cancel',            [AdminComplaintController::class, 'cancel']);
        Route::patch('/complaints/{id}/close',             [AdminComplaintController::class, 'close']);
        Route::get('/complaints/{id}/return-slip',         [AdminComplaintController::class, 'returnSlip'])->whereNumber('id');

        Route::patch('/users/{id}/wallet/top-up', [\App\Http\Controllers\Admin\UserController::class, 'walletTopUp']);

        // ── VIP Requests ──────────────────────────────────────────────────
        Route::get('/vip-requests/stats',            [AdminVipRequestController::class, 'stats']);
        Route::get('/vip-requests',                  [AdminVipRequestController::class, 'index']);
        Route::get('/vip-requests/{id}',             [AdminVipRequestController::class, 'show']);
        Route::patch('/vip-requests/{id}/approve',   [AdminVipRequestController::class, 'approve']);
        Route::patch('/vip-requests/{id}/complete',  [AdminVipRequestController::class, 'complete']);
        Route::patch('/vip-requests/{id}/reject',    [AdminVipRequestController::class, 'reject']);
        Route::patch('/vip-requests/{id}/note',      [AdminVipRequestController::class, 'addNote']);

        // ── Payment requests (manual WhatsApp payments) ───────────────────
        Route::get('/payment-requests',                        [\App\Http\Controllers\Admin\AdminPaymentRequestController::class, 'index']);
        Route::get('/payment-requests/pending-count',          [\App\Http\Controllers\Admin\AdminPaymentRequestController::class, 'pendingCount']);
        Route::get('/payment-requests/settings',               [\App\Http\Controllers\Admin\AdminPaymentRequestController::class, 'settings']);
        Route::put('/payment-requests/settings',               [\App\Http\Controllers\Admin\AdminPaymentRequestController::class, 'updateSettings']);
        Route::post('/payment-requests/direct/wallet-top-up',  [\App\Http\Controllers\Admin\AdminPaymentRequestController::class, 'directTopUp']);
        Route::post('/payment-requests/direct/plan-change',    [\App\Http\Controllers\Admin\AdminPaymentRequestController::class, 'directPlanChange']);
        Route::get('/payment-requests/{id}',                   [\App\Http\Controllers\Admin\AdminPaymentRequestController::class, 'show'])->whereNumber('id');
        Route::post('/payment-requests/{id}/approve',          [\App\Http\Controllers\Admin\AdminPaymentRequestController::class, 'approve'])->whereNumber('id');
        Route::post('/payment-requests/{id}/reject',           [\App\Http\Controllers\Admin\AdminPaymentRequestController::class, 'reject'])->whereNumber('id');

        // ── Ads: wallets & top-ups ────────────────────────────────────────
        Route::post('/ads/wallets/{seller}/adjust',  [\App\Http\Controllers\Admin\AdminAdWalletController::class, 'adjust'])->whereNumber('seller');
        Route::get('/ads/top-ups',                   [\App\Http\Controllers\Admin\AdminAdWalletController::class, 'topUps']);
        Route::post('/ads/top-ups/{id}/confirm',     [\App\Http\Controllers\Admin\AdminAdWalletController::class, 'confirm'])->whereNumber('id');
        Route::post('/ads/top-ups/{id}/reject',      [\App\Http\Controllers\Admin\AdminAdWalletController::class, 'reject'])->whereNumber('id');

        // ── Ads: overview, campaigns (moderation), settings, wallets ─────
        Route::get('/ads/overview',                  [\App\Http\Controllers\Admin\AdminAdsController::class, 'overview']);
        Route::get('/ads/campaigns',                 [\App\Http\Controllers\Admin\AdminAdsController::class, 'campaigns']);
        Route::get('/ads/campaigns/{id}',            [\App\Http\Controllers\Admin\AdminAdsController::class, 'campaign'])->whereNumber('id');
        Route::post('/ads/campaigns/{id}/reject',    [\App\Http\Controllers\Admin\AdminAdsController::class, 'reject'])->whereNumber('id');
        Route::post('/ads/campaigns/{id}/pause',     [\App\Http\Controllers\Admin\AdminAdsController::class, 'pause'])->whereNumber('id');
        Route::post('/ads/campaigns/{id}/resume',    [\App\Http\Controllers\Admin\AdminAdsController::class, 'resume'])->whereNumber('id');
        Route::get('/ads/settings',                  [\App\Http\Controllers\Admin\AdminAdsController::class, 'settings']);
        Route::put('/ads/settings',                  [\App\Http\Controllers\Admin\AdminAdsController::class, 'updateSettings']);
        Route::get('/ads/wallets',                   [\App\Http\Controllers\Admin\AdminAdsController::class, 'wallets']);
        Route::get('/ads/wallets/{seller}',          [\App\Http\Controllers\Admin\AdminAdsController::class, 'wallet'])->whereNumber('seller');



        // Search: missed queries (grow resources/search/synonyms.txt) + index health
        Route::get('/search/missed',                     [\App\Http\Controllers\Admin\AdminSearchController::class, 'missed']);
        Route::get('/search/health',                     [\App\Http\Controllers\Admin\AdminSearchController::class, 'health']);

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
        Route::get('orders/{id}/details', [FinanceController::class, 'orderDetails'])->whereNumber('id');
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

    // ── Subscription Management ──────────────────────────────────────────
    // (Was nested inside the settlements prefix → served at
    //  /admin/settlements/subscriptions/* while the admin panel called
    //  /admin/subscriptions/* — every request 404'd.)
    Route::prefix('subscriptions')->group(function () {
        Route::get('/',                                 [AdminSubscriptionController::class, 'index']);
        Route::get('/stats',                            [AdminSubscriptionController::class, 'stats']);
        Route::get('/{sellerId}',                       [AdminSubscriptionController::class, 'show'])->whereNumber('sellerId');
        Route::post('/{sellerId}/assign-plan',          [AdminSubscriptionController::class, 'assignPlan']);
        Route::post('/{sellerId}/force-plan',           [AdminSubscriptionController::class, 'assignPlan']);   // legacy alias
        Route::post('/{sellerId}/end-date',             [AdminSubscriptionController::class, 'changeEndDate']);
        Route::post('/{sellerId}/free-days',            [AdminSubscriptionController::class, 'grantFreeDays']);
        Route::post('/{sellerId}/trial',                [AdminSubscriptionController::class, 'startTrial']);
        Route::post('/{sellerId}/suspend',              [AdminSubscriptionController::class, 'suspend']);
        Route::post('/{sellerId}/reinstate',            [AdminSubscriptionController::class, 'reinstate']);
        Route::post('/{sellerId}/cancel',               [AdminSubscriptionController::class, 'cancel']);
        Route::put('/{sellerId}/commission-override',   [AdminSubscriptionController::class, 'setCommissionOverride']);
        Route::delete('/{sellerId}/commission-override',[AdminSubscriptionController::class, 'removeCommissionOverride']);
    });

    // ── Subscription plans + platform default commission ─────────────────
    Route::prefix('subscription-plans')->group(function () {
        Route::get('/',              [AdminPlanController::class, 'index']);
        Route::post('/',             [AdminPlanController::class, 'store']);
        Route::put('/{id}',          [AdminPlanController::class, 'update']);
        Route::patch('/{id}/toggle', [AdminPlanController::class, 'toggle']);
        Route::patch('/{id}/default',[AdminPlanController::class, 'makeDefault']);
        Route::delete('/{id}',       [AdminPlanController::class, 'destroy']);
        Route::post('/{id}/restore', [AdminPlanController::class, 'restore']);
        Route::patch('/{id}/recommended', [AdminPlanController::class, 'recommend']);

        // Pricing-page features (/become-a-vendor cards)
        Route::get('/{planId}/display-features',          [\App\Http\Controllers\Admin\AdminPlanDisplayFeatureController::class, 'index'])->whereNumber('planId');
        Route::post('/{planId}/display-features',         [\App\Http\Controllers\Admin\AdminPlanDisplayFeatureController::class, 'store'])->whereNumber('planId');
        Route::put('/{planId}/display-features/reorder',  [\App\Http\Controllers\Admin\AdminPlanDisplayFeatureController::class, 'reorder'])->whereNumber('planId');
        Route::put('/{planId}/display-features/{id}',     [\App\Http\Controllers\Admin\AdminPlanDisplayFeatureController::class, 'update'])->whereNumber(['planId', 'id']);
        Route::delete('/{planId}/display-features/{id}',  [\App\Http\Controllers\Admin\AdminPlanDisplayFeatureController::class, 'destroy'])->whereNumber(['planId', 'id']);
    });
    Route::get('/commission-settings', [AdminPlanController::class, 'commissionSettings']);
    Route::put('/commission-settings', [AdminPlanController::class, 'updateCommissionSettings']);


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
            // Refund admin routes — stats MUST be before /{id}
            Route::get('/refunds/stats',        [RefundDeliveryController::class, 'stats']);
            Route::get('/refunds',              [RefundDeliveryController::class, 'index']);
            Route::post('/refunds/{id}/assign', [RefundDeliveryController::class, 'assign']);
        });

        // ── Delivery Guy only ─────────────────────────────────────────────
        Route::middleware('delivery:guy')->group(function () {
            Route::get('/my-orders',           [DeliveryController::class, 'myOrders']);
            Route::put('/orders/{id}/status',  [DeliveryController::class, 'updateStatus']);
            Route::get('/my-refunds',          [RefundDeliveryController::class, 'myRefunds']);
            Route::put('/refunds/{id}/status', [RefundDeliveryController::class, 'updateGuyStatus']);
        });

        // ── Shared — LAST so /active is not swallowed by /{id} ───────────
        Route::get('/orders/{id}',  [DeliveryController::class, 'showOrder']);
        Route::get('/refunds/{id}', [RefundDeliveryController::class, 'show']); 
        });