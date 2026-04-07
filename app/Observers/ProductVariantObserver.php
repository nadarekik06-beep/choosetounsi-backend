<?php

namespace App\Observers;

use App\Models\ProductVariant;

class ProductVariantObserver
{
    /**
     * Fires after any variant is created or updated.
     * Covers: is_active toggled, stock changed, new variant added.
     */
    public function saved(ProductVariant $variant): void
    {
        $variant->product->syncActiveStatusFromVariants();
    }

    /**
     * Fires after a variant is deleted.
     * Covers: single delete, or the last variant being removed.
     *
     * NOTE: Product::boot() calls $this->variants()->delete() which
     * fires this observer per row — that's fine, the final state
     * after all deletes is what matters.
     */
    public function deleted(ProductVariant $variant): void
    {
        // Guard: product may itself be in the process of being deleted.
        // If so, skip — the product row will be gone anyway.
        if ($variant->product()->withTrashed()->exists() === false) {
            return;
        }

        $variant->product->syncActiveStatusFromVariants();
    }
}