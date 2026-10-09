<?php

namespace App\Observers;

use App\Models\Product;
use App\Jobs\NotifyFavoriteWatchers;
use App\Services\StockAlertService;
use App\Support\StockLevels;

/**
 * ProductObserver
 *
 * Fires on Eloquent model events for simple (non-variant) products.
 *
 * Same caveat as ProductVariantObserver: raw `->decrement()` calls in
 * CheckoutController bypass this — those are handled directly in the
 * controller via StockAlertService::recordSales().
 *
 * What we watch:
 *   updated() — fires after a product is saved with a changed stock value.
 *               Only acts if the product has NO variants (variant products
 *               are tracked at the variant level via ProductVariantObserver).
 */
class ProductObserver
{
    public function __construct(private StockAlertService $stockAlertService) {}

    /** Created at or below the threshold: flagged silently, never alerts. */
    public function created(Product $product): void
    {
        $this->stockAlertService->syncFlags($product);
    }

    public function updated(Product $product): void
    {
        $this->notifyFavorites($product);

        // New per-product threshold: the flags of the product/its variants follow it silently
        if ($product->wasChanged('low_stock_threshold')) {
            $this->stockAlertService->syncProductFlags($product);
        }

        // Only act when stock actually changed
        if (!$product->wasChanged('stock')) {
            return;
        }

        // Variant products: the total is the active variants' stock, whatever the
        // form sent. Their alerts are tracked at the variant level.
        if ($product->has_variants) {
            StockLevels::syncProductStock($product->id);
            return;
        }

        // Seller/admin edit: flags follow the stock silently (sales alert from checkout)
        $this->stockAlertService->syncFlags($product);
    }

    /** Buyers who favourited it: price drop (base price) or back in stock (products without variants). */
    private function notifyFavorites(Product $product): void
    {
        if ($product->wasChanged('price')) {
            $old = (float) $product->getOriginal('price');
            $new = (float) $product->price;
            $min = (float) config('notifications.price_drop_min_percent');
            if ($old > 0 && $new > 0 && $new <= $old * (1 - $min / 100)) {
                NotifyFavoriteWatchers::dispatch($product->id, 'price_drop', null, $old, $new);
            }
        }

        if ($product->wasChanged('stock') && (int) $product->getOriginal('stock') <= 0 && (int) $product->stock > 0
            && !$product->has_variants) {
            NotifyFavoriteWatchers::dispatch($product->id, 'back_in_stock');
        }
    }
}
