<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\Recommendation\RelatedProducts;

/**
 * Clears the cached "similaires" / "complétez le look" lists when a product change can
 * alter them. Stock only counts when it crosses zero. Bulk updates without model events
 * are covered by the short cache lifetime (config/related_products.php).
 */
class RelatedProductsObserver
{
    private const FIELDS = ['is_active', 'is_approved', 'category_id', 'subcategory_id', 'gender', 'price', 'name', 'seller_id'];

    public function saved(Product $product): void
    {
        if ($product->wasRecentlyCreated || $product->wasChanged(self::FIELDS)) {
            RelatedProducts::bump();
            return;
        }
        if ($product->wasChanged('stock')
            && ((int) $product->getOriginal('stock') > 0) !== ((int) $product->stock > 0)) {
            RelatedProducts::bump();
        }
    }

    public function deleted(Product $product): void  { RelatedProducts::bump(); }
    public function restored(Product $product): void { RelatedProducts::bump(); }
}
