<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\Catalog\SubcategoryBar;
use Illuminate\Database\Eloquent\Model;

/**
 * Clears the cached subcategory bars (GET /categories/{slug}/subcategory-bar) when a product
 * change can alter which subcategories show or which image they use. Plain stock changes
 * only count when stock crosses zero, so checkouts don't rebuild the bars all the time.
 * The 10-minute cache covers bulk updates that fire no model events.
 */
class SubcategoryBarObserver
{
    private const FIELDS = ['is_active', 'is_approved', 'category_id', 'subcategory_id', 'gender', 'deleted_at'];

    public function saved(Model $model): void
    {
        if (!$model instanceof Product) { SubcategoryBar::flush(); return; }  // ProductImage

        if ($model->wasRecentlyCreated || $model->wasChanged(self::FIELDS)) { SubcategoryBar::flush(); return; }
        if ($model->wasChanged('stock')) {
            $before = (int) $model->getOriginal('stock');
            $after  = (int) $model->stock;
            if (($before > 0) !== ($after > 0)) SubcategoryBar::flush();
        }
    }

    public function deleted(Model $model): void  { SubcategoryBar::flush(); }
    public function restored(Model $model): void { SubcategoryBar::flush(); }
}
