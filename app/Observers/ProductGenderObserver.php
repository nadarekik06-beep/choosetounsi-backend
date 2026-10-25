<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\Gender\GenderResolver;

/**
 * Keeps products.gender in step with what the seller (or admin) saves, without touching
 * the seller form or its request: it listens to the rows that flow already writes.
 *
 *   Product created / subcategory changed / override changed → recompute (here)
 *   gender attribute value saved or deleted                   → ProductAttributeGenderObserver
 *
 * A second recompute runs once at the end of the request, which also covers flows that
 * delete attribute rows in bulk (no model events). A failure here never breaks the save.
 */
class ProductGenderObserver
{
    /** @var array<int,true> product ids to recompute once the request ends */
    private static array $pending = [];
    private static bool $registered = false;

    public function created(Product $product): void
    {
        self::sync($product->id);
    }

    public function updated(Product $product): void
    {
        if ($product->wasChanged(['subcategory_id', 'category_id', 'gender_override'])) {
            self::sync($product->id);
        }
        self::defer($product->id);
    }

    public static function sync(?int $productId): void
    {
        if (!$productId) return;
        try {
            app(GenderResolver::class)->sync($productId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function defer(?int $productId): void
    {
        if (!$productId) return;
        self::$pending[$productId] = true;

        if (self::$registered) return;
        self::$registered = true;

        app()->terminating(function () {
            $ids = array_keys(self::$pending);
            self::$pending    = [];
            self::$registered = false;
            foreach ($ids as $id) self::sync($id);
        });
    }
}
