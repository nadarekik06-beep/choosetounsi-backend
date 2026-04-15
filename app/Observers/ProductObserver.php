<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\StockAlertService;

/**
 * ProductObserver
 *
 * Fires on Eloquent model events for simple (non-variant) products.
 *
 * Same caveat as ProductVariantObserver: raw `->decrement()` calls in
 * CheckoutController bypass this — those are handled directly in the
 * controller via StockAlertService.
 *
 * What we watch:
 *   updated() — fires after a product is saved with a changed stock value.
 *               Only acts if the product has NO variants (variant products
 *               are tracked at the variant level via ProductVariantObserver).
 */
class ProductObserver
{
    public function __construct(private StockAlertService $stockAlertService) {}

    public function updated(Product $product): void
    {
        // Only act when stock actually changed
        if (!$product->wasChanged('stock')) {
            return;
        }

        // Skip variant products — their stock is tracked at the variant level.
        // Checking variants()->exists() would fire a DB query on every product
        // save. We use the has_variants accessor instead which uses the loaded
        // relation when available, falling back to exists() only if needed.
        if ($product->has_variants) {
            return;
        }

        $this->stockAlertService->checkProduct($product);
    }
}