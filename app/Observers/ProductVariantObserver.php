<?php

namespace App\Observers;

use App\Models\ProductVariant;
use App\Services\StockAlertService;

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
        $this->refreshSearch($variant);
    }

    /**
     * Fires after a variant is UPDATED (not created).
     * Only acts when stock actually changed — triggers stock alerts.
     *
     * NOTE: saved() fires for both create and update, but we only
     * want stock alerts on updates (not on initial variant creation
     * where the seller is just building their catalog).
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

        $this->stockAlertService->checkVariant($variant, $product);
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