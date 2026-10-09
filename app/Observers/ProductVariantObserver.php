<?php

namespace App\Observers;

use App\Models\ProductVariant;
use App\Services\StockAlertService;
use App\Support\StockLevels;

class ProductVariantObserver
{
    public function __construct(private StockAlertService $stockAlertService) {}

    /**
     * Fires after any variant is created or updated.
     * Covers: is_active toggled, stock changed, new variant added.
     */
    public function saved(ProductVariant $variant): void
    {
        $variant->product->syncActiveStatusFromVariants();
        StockLevels::syncProductStock((int) $variant->product_id);
        $this->refreshSearch($variant);
    }

    /**
     * A new variant built at 2 units is already "low": flag it silently,
     * so its first sale doesn't alert (only sales crossing the threshold do).
     */
    public function created(ProductVariant $variant): void
    {
        if ($product = $variant->product()->with('seller')->first()) {
            $this->stockAlertService->syncFlags($product, $variant);
        }
    }

    /**
     * Stock edited by the seller/admin (form, restock): the alert flags
     * follow it silently. Sales alert from CheckoutController instead.
     */
    public function updated(ProductVariant $variant): void
    {
        if (!$variant->wasChanged('stock')) {
            return;
        }

        // Buyers who favourited it: this variant is available again
        if ((int) $variant->getOriginal('stock') <= 0 && (int) $variant->stock > 0 && $variant->is_active !== false) {
            \App\Jobs\NotifyFavoriteWatchers::dispatch((int) $variant->product_id, 'back_in_stock', (int) $variant->id);
        }

        $product = $variant->product()->with('seller')->first();
        if (!$product) return;

        $this->stockAlertService->syncFlags($product, $variant);
    }

    /**
     * Fires after a variant is deleted.
     */
    public function deleted(ProductVariant $variant): void
    {
        if ($variant->product()->withTrashed()->exists() === false) {
            return;
        }

        $variant->product->syncActiveStatusFromVariants();
        StockLevels::syncProductStock((int) $variant->product_id);
        $this->refreshSearch($variant);
    }

    /**
     * Variant options (colors, sizes) are part of the product's search text, and
     * syncActiveStatusFromVariants() saves quietly, so the observers don't see a status change.
     */
    private function refreshSearch(ProductVariant $variant): void
    {
        app(\App\Services\Search\SearchIndexer::class)->refresh((int) $variant->product_id);
        if (!config('search.indexing')
            || (!$variant->wasRecentlyCreated && !$variant->wasChanged('is_active') && $variant->exists)) {
            return;
        }
        \App\Jobs\IndexProductImages::dispatch($variant->product_id);
    }
}
