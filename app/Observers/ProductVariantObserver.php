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
    }
}