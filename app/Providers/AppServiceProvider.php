<?php

namespace App\Providers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Observers\ProductObserver;
use App\Observers\ProductVariantObserver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Observers\OrderObserver;
use App\Observers\SellerOrderObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One settings snapshot per request (it memoizes the cached settings).
        $this->app->singleton(\App\Services\Ads\AdSettings::class);
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Variant status drives product status — centralized here.
        ProductVariant::observe(ProductVariantObserver::class);

        // Stock alert observers — handles seller dashboard edits.
        // Checkout decrement path is handled directly in CheckoutController.
        Product::observe(ProductObserver::class);
        Order::observe(OrderObserver::class);
        // Cancelled seller orders give their flash-sale units back
        SellerOrder::observe(SellerOrderObserver::class);

        // Search index sync (Meilisearch); Scout itself syncs the Product documents.
        // (ProductAttributeValue lives in Productattributevalue.php: only autoloadable through an
        // optimized classmap on case-sensitive filesystems, hence the class_exists guard.)
        foreach ([Product::class, \App\Models\ProductImage::class, \App\Models\ProductAttributeValue::class,
                  \App\Models\Category::class, \App\Models\Subcategory::class] as $model) {
            if (class_exists($model)) {
                $model::observe(\App\Observers\SearchIndexObserver::class);
            }
        }

        // Requests to the embedding service (choosetounsi-ai-service) carry its shared secret:
        // Http::ai()->post(config('services.ai.url') . '/embed/text', ...)
        Http::macro('ai', fn () => Http::withHeaders(array_filter([
            'X-AI-Token' => config('services.ai.token'),
        ])));
    }
}