<?php

namespace App\Observers;

use App\Jobs\IndexProductImages;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\Search\FingerprintIndex;
use App\Services\Search\SearchIndexer;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the search indexes in step with the catalog.
 *
 * MySQL text index (product_search_index, the search bar), always, synchronously:
 *  - product text / category / status, attribute values changed → its row is rebuilt
 *  - category / subcategory renamed                             → rows of its products rebuilt
 *
 * Photo search (image_fingerprints, IndexProductImages job), when search.indexing is on:
 *  - product goes live / offline / deleted / restored  → its photos are fingerprinted or removed
 *  - product photo added, replaced or deleted           → photos re-fingerprinted (queued)
 *  - product moved to another category                  → cached photo index rebuilt (centroids)
 *  - seller activated / deactivated                     → cached photo index rebuilt
 *
 * Meilisearch (semantic fallback), when search.indexing is on; the product document itself is
 * synced by Scout's own observer on Product, queued:
 *  - attribute values change                            → product document refreshed
 *  - category / subcategory renamed                     → documents of its products refreshed
 *
 * Variants are handled in ProductVariantObserver, translations in ProductTranslator.
 */
class SearchIndexObserver
{
    /** Product columns that change what the search bar finds. */
    const TEXT_COLUMNS = ['name', 'description', 'short_description', 'translations', 'category_id',
                          'subcategory_id', 'is_approved', 'is_active', 'deleted_at'];

    public function saved(Model $model): void
    {
        $this->refreshText($model);
        if (!config('search.indexing')) {
            return;
        }
        match (true) {
            $model instanceof Product => $this->productSaved($model),
            $model instanceof ProductImage => $this->imagesChanged($model->product_id),
            $model instanceof ProductAttributeValue => $this->refreshProduct($model->product_id),
            $model instanceof Category, $model instanceof Subcategory => $this->categoryRenamed($model),
            $model instanceof User => $model->role === 'seller' && $model->wasChanged('is_active')
                ? app(FingerprintIndex::class)->bump() : null,
            default => null,
        };
    }

    public function deleted(Model $model): void
    {
        $this->refreshText($model, true);
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
        $this->refreshText($model, true);
        if (config('search.indexing') && $model instanceof Product) {
            IndexProductImages::dispatch($model->id);
        }
    }

    public function forceDeleted(Model $model): void
    {
        $this->deleted($model);
    }

    private function refreshText(Model $model, bool $always = false): void
    {
        $indexer = app(SearchIndexer::class);
        match (true) {
            $model instanceof Product => ($always || $model->wasRecentlyCreated || $model->wasChanged(self::TEXT_COLUMNS))
                ? $indexer->refresh($model->id) : null,
            $model instanceof ProductAttributeValue => $indexer->refresh((int) $model->product_id),
            $model instanceof Category => $model->wasChanged(['name', 'name_fr', 'name_ar']) ? $indexer->refreshCategory('category_id', $model->id) : null,
            $model instanceof Subcategory => $model->wasChanged(['name', 'name_fr', 'name_ar']) ? $indexer->refreshCategory('subcategory_id', $model->id) : null,
            default => null,
        };
    }

    private function productSaved(Product $product): void
    {
        $inserted = $product->wasRecentlyCreated && !$product->getChanges();
        if ($inserted || $product->wasChanged(['is_approved', 'is_active', 'deleted_at'])) {
            IndexProductImages::dispatch($product->id);
        } elseif ($product->wasChanged(['category_id', 'subcategory_id'])) {
            app(FingerprintIndex::class)->bump();
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
