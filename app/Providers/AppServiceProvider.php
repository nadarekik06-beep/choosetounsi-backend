<?php

namespace App\Providers;

use App\Models\ProductVariant;
use App\Observers\ProductVariantObserver;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Variant status drives product status — centralized here.
        ProductVariant::observe(ProductVariantObserver::class);
    }
}