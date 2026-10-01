<?php

namespace App\Observers;

use App\Jobs\IndexProductImages;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\Subcategory;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps Meilisearch in step with the catalog (the product document itself is synced by
 * Scout's own observer on Product, queued):
 *
 *  - product goes live / offline / deleted / restored  → its photos are (re)indexed or removed
 *  - product photo added, replaced or deleted           → photos re-embedded (queued)
 *  - attribute values change                            → product document refreshed
 *  - category / subcategory renamed                     → documents of its products refreshed
 *
 * Variants are handled in ProductVariantObserver, translations in ProductTranslator.
 */
class SearchIndexObserver
{
    public function saved(Model $model): void
    {
        if (!config('search.indexing')) {
            return;
        }
        match (true) {
            $model instanceof Product => $this->productSaved($model),
            $model instanceof ProductImage => $this->imagesChanged($model->product_id),
            $model instanceof ProductAttributeValue => $this->refreshProduct($model->product_id),
            $model instanceof Category, $model instanceof Subcategory => $this->categoryRenamed($model),
            default => null,
        };
    }

    public function deleted(Model $model): void
    {
        if (!config('search.indexing')) {
            return;
        }
        match (true) {
            $model instanceof Product => IndexProductImages::dispatch($model->id),
            $model instanceof ProductImage => $this->imagesChanged($model->product_id),
            $model instanceof ProductAttributeValue => $this->refreshProduct($model->product_id),
            default => null,
        };
    }

    public function restored(Model $model): void
    {
        if (config('search.indexing') && $model instanceof Product) {
            IndexProductImages::dispatch($model->id);
        }
    }

    public function forceDeleted(Model $model): void
    {
        $this->deleted($model);
    }

    private function productSaved(Product $product): void
    {
        $inserted = $product->wasRecentlyCreated && !$product->getChanges();
        if ($inserted || $product->wasChanged(['is_approved', 'is_active', 'deleted_at'])) {
            IndexProductImages::dispatch($product->id);
        }
    }

    private function imagesChanged(?int $productId): void
    {
        if ($productId) {
            IndexProductImages::dispatch($productId);
        }
    }

    private function refreshProduct(?int $productId): void
    {
        Product::find($productId)?->searchable();
    }

    private function categoryRenamed(Model $category): void
    {
        if ($category->wasRecentlyCreated || !$category->wasChanged(['name', 'name_fr', 'name_ar'])) {
            return;
        }
        $column = $category instanceof Category ? 'category_id' : 'subcategory_id';
        Product::where($column, $category->id)->where('is_approved', true)->where('is_active', true)
            ->orderBy('id')->searchable(200);
    }
}
