<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\AttributeOption;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SellerProductController extends Controller
{
    // ── Index ──────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $seller = $request->user();
        $query  = $seller->products()->with([
            'category:id,name,slug',
            'subcategory:id,name,slug',
            'primaryImage',
        ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%$s%")->orWhere('sku', 'like', "%$s%"));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('is_approved')) {
            $query->where('is_approved', filter_var($request->is_approved, FILTER_VALIDATE_BOOLEAN));
        }

        $products = $query->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 12));

        $products->getCollection()->transform(function ($p) {
            $p->primary_image_url = $p->primaryImage
                ? Storage::url($p->primaryImage->image_path)
                : null;
            $p->has_variants  = $p->variants()->exists();
            $p->variant_stock = $p->variants()->sum('stock');
            return $p;
        });

        return response()->json(['success' => true, 'data' => $products]);
    }

// ── Show ───────────────────────────────────────────────────────────────────

    public function show(Request $request, $id)
    {
        $product = $request->user()->products()
            ->with([
                'category:id,name,slug',
                'subcategory:id,name,slug',
                'images',
                'primaryImage',
                'attributeValues.attribute',
                'variants.attributeOptions.attribute',
            ])
            ->findOrFail($id);

        try {
            $product->load('variants.images');
        } catch (\Throwable $e) {
            Log::warning('[SellerProduct::show] variants.images not available: ' . $e->getMessage());
        }

        $product->primary_image_url = $product->primaryImage
            ? Storage::url($product->primaryImage->image_path)
            : null;

        $product->images->each(fn($img) => $img->url = Storage::url($img->image_path));

        $product->existing_attributes = $product->attributeValues
            ->mapWithKeys(fn($v) => [
                $v->attribute->slug => $v->attribute->decodeValue($v->value),
            ]);

        $product->variant_rows = $product->variants->map(function ($v) {

            $colorGroup  = [];
            $nonColorMap = [];

            foreach ($v->attributeOptions as $opt) {
                $attr = $opt->getAttribute('attribute') ?? $opt->getRelation('attribute');
                $slug = $attr ? $attr->slug : null;

                if ($slug === 'color') {
                    $colorGroup[] = [
                        'id'        => $opt->id,
                        'value'     => $opt->value,
                        'color_hex' => $opt->color_hex ?? null,
                    ];
                } elseif ($slug) {
                    $nonColorMap[$slug] = [
                        'id'        => $opt->id,
                        'value'     => $opt->value,
                        'color_hex' => $opt->color_hex ?? null,
                    ];
                }
            }

            usort($colorGroup, fn($a, $b) => $a['id'] <=> $b['id']);
            ksort($nonColorMap);

            $optionMap = [];

            if (!empty($colorGroup)) {
                $optionMap['color'] = [
                    'id'        => $colorGroup[0]['id'],
                    'ids'       => array_column($colorGroup, 'id'),
                    'value'     => implode('+', array_column($colorGroup, 'value')),
                    'color_hex' => $colorGroup[0]['color_hex'],
                ];
            }

            foreach ($nonColorMap as $slug => $entry) {
                $optionMap[$slug] = $entry;
            }

            $labelParts = [];
            if (isset($optionMap['color'])) {
                $labelParts[] = $optionMap['color']['value'];
            }
            foreach ($optionMap as $slug => $entry) {
                if ($slug !== 'color') {
                    $labelParts[] = $entry['value'];
                }
            }
            $label = implode(' / ', array_filter($labelParts));

            $imageUrls = [];
            if ($v->relationLoaded('images')) {
                $imageUrls = $v->images
                    ->map(fn($i) => Storage::url($i->image_path))
                    ->toArray();
            }

            return [
                'id'             => $v->id,
                'option_ids'     => $v->attributeOptions->pluck('id')->toArray(),
                'stock'          => $v->stock,
                'price_override' => $v->price_override !== null ? (string) $v->price_override : '',
                'sku'            => $v->sku ?? '',
                'is_active'      => $v->is_active,
                'label'          => $label,
                'option_map'     => $optionMap,
                'image_urls'     => $imageUrls,
            ];
        });

        return response()->json(['success' => true, 'data' => $product]);
    }
    private function saveVariantImages(Product $product, Request $request): void
    {
        $allFiles = $request->allFiles();
        if (empty($allFiles['variant_images'])) return;
 
        $variantImagesInput = $allFiles['variant_images'];
        if (!is_array($variantImagesInput)) return;
 
        // Pre-load all variants for this product to validate ownership.
        $validVariantIds = $product->variants()->pluck('id')->flip();
 
        $maxOrder = $product->images()->max('order') ?? -1;
        $orderIdx = 0;
 
        foreach ($variantImagesInput as $variantIdStr => $files) {
            $variantId = (int) $variantIdStr;
 
            // Security: only accept variant IDs that belong to THIS product.
            if (!isset($validVariantIds[$variantId])) {
                Log::warning("[SellerProduct::saveVariantImages] Invalid variant_id: {$variantId} for product {$product->id}");
                continue;
            }
 
            foreach ((array) $files as $file) {
                if (!$file || !method_exists($file, 'isValid') || !$file->isValid()) continue;
 
                $path = $file->store('products', 'public');
 
                ProductImage::create([
                    'product_id'      => $product->id,
                    'variant_id'      => $variantId,
                    'color_option_id' => null,
                    'image_path'      => $path,
                    'order'           => $maxOrder + $orderIdx + 1,
                    'is_primary'      => false,   // variant images are never the product primary
                ]);
 
                $orderIdx++;
            }
        }
    }
 

    // ── Stats ──────────────────────────────────────────────────────────────────

    public function stats(Request $request)
    {
        $seller = $request->user();
        return response()->json([
            'success' => true,
            'data'    => [
                'total'        => $seller->products()->count(),
                'active'       => $seller->products()->where('is_active', true)->count(),
                'approved'     => $seller->products()->where('is_approved', true)->count(),
                'pending'      => $seller->products()->where('is_approved', false)->count(),
                'total_stock'  => (int) $seller->products()->sum('stock'),
                'out_of_stock' => $seller->products()->where('stock', 0)->count(),
                'total_views'  => (int) $seller->products()->sum('views'),
            ],
        ]);
    }

    // ── Store ──────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        try {
            return $this->doStore($request);
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::store] CRASH: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success'     => false,
                'debug_error' => $e->getMessage(),
                'debug_file'  => basename($e->getFile()),
                'debug_line'  => $e->getLine(),
            ], 500);
        }
    }

    private function doStore(Request $request)
    {
        Log::info('[SellerProduct::store] START', [
            'has_images'       => $request->hasFile('images'),
            'has_color_images' => $request->hasFile('color_images'),
            'has_variants'     => !empty($request->input('variants')),
            'name'             => $request->input('name'),
        ]);

        try {
            $request->validate([
                'name'        => 'required|string|max:255',
                'price'       => 'required|numeric|min:0',
                'stock'       => 'required|integer|min:0',
                'category_id' => 'required|exists:categories,id',
            ]);
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::store] VALIDATION FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }

        $seller   = $request->user();
        $isActive = filter_var($request->input('is_active', true), FILTER_VALIDATE_BOOLEAN);

        try {
            $product = $seller->products()->create([
                'name'              => $request->name,
                'slug'              => $this->uniqueSlug($request->slug ?: $request->name),
                'sku'               => $request->sku ?: null,
                'description'       => $request->description ?? null,
                'short_description' => $request->short_description ?? null,
                'price'             => $request->price,
                'stock'             => $request->stock,
                'category_id'       => $request->category_id,
                'subcategory_id'    => $request->subcategory_id ?: null,
                'is_active'         => $isActive,
                'is_approved'       => false,
                'featured'          => false,
                'views'             => 0,
            ]);
            Log::info('[SellerProduct::store] Product created', ['id' => $product->id]);
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::store] PRODUCT CREATE FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $this->saveAttributes($product, $request);
            Log::info('[SellerProduct::store] Attributes saved');
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::store] ATTRIBUTES FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $this->saveVariants($product, $request);
            // FIX: Only sync active status from variants when the request actually
            // contained variant data. For simple products (no variants submitted),
            // the seller's chosen is_active is already persisted above — calling
            // syncActiveStatusFromVariants() with zero variants would force
            // is_active = false and override the seller's intent.
            if (!empty($request->input('variants', []))) {
                $product->fresh()->syncActiveStatusFromVariants();
            }
            Log::info('[SellerProduct::store] Variants saved');
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::store] VARIANTS FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $this->saveGeneralImages($product, $request);
            Log::info('[SellerProduct::store] General images saved');
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::store] GENERAL IMAGES FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $this->saveColorImages($product, $request);
            Log::info('[SellerProduct::store] Color images saved');
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::store] COLOR IMAGES FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }

        $this->notifyAdmins('created', $product, $seller);

        Log::info('[SellerProduct::store] DONE', ['id' => $product->id]);

        return response()->json([
            'success' => true,
            'message' => 'Product created! It will be reviewed by an admin.',
            'data'    => $product->load(['images', 'category', 'variants.attributeOptions.attribute']),
        ], 201);
    }

    // ── Update ─────────────────────────────────────────────────────────────────

    public function update(Request $request, $id)
    {
        $seller  = $request->user();
        $product = $seller->products()->findOrFail($id);

        Log::info('[SellerProduct::update] START', ['id' => $id]);

        $isActive = $request->has('is_active')
            ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
            : $product->is_active;

        try {
            $product->update([
                'name'              => $request->name              ?? $product->name,
                'slug'              => $this->uniqueSlug($request->slug ?: ($request->name ?? $product->name), $product->id),
                'sku'               => $request->sku               ?? $product->sku,
                'description'       => $request->description       ?? $product->description,
                'short_description' => $request->short_description ?? $product->short_description,
                'price'             => $request->price             ?? $product->price,
                'stock'             => $request->stock             ?? $product->stock,
                'category_id'       => $request->category_id      ?? $product->category_id,
                'subcategory_id'    => $request->subcategory_id    ?? $product->subcategory_id,
                'is_active'         => $isActive,
            ]);
            Log::info('[SellerProduct::update] Product updated');
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::update] PRODUCT UPDATE FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $this->saveAttributes($product, $request);
            Log::info('[SellerProduct::update] Attributes saved');
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::update] ATTRIBUTES FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $this->saveVariants($product, $request);
            // FIX: Only sync active status from variants when the request actually
            // contained variant data. For simple products (no variants submitted),
            // the seller's chosen is_active is already persisted above — calling
            // syncActiveStatusFromVariants() with zero variants would force
            // is_active = false and override the seller's intent.
            if (!empty($request->input('variants', []))) {
                $product->fresh()->syncActiveStatusFromVariants();
            }
            Log::info('[SellerProduct::update] Variants saved');
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::update] VARIANTS FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }

        if ($deleteIds = $request->input('delete_image_ids', [])) {
            foreach ($product->images()->whereIn('id', (array) $deleteIds)->get() as $img) {
                Storage::disk('public')->delete($img->image_path);
                $img->delete();
            }
        }

        try {
            $this->saveGeneralImages($product, $request);
            Log::info('[SellerProduct::update] General images saved');
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::update] GENERAL IMAGES FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $this->saveColorImages($product, $request);
            Log::info('[SellerProduct::update] Color images saved');
        } catch (\Throwable $e) {
            Log::error('[SellerProduct::update] COLOR IMAGES FAILED', ['error' => $e->getMessage()]);
            throw $e;
        }

        $this->notifyAdmins('updated', $product, $seller);

        return response()->json([
            'success' => true,
            'message' => 'Product updated.',
            'data'    => $product->fresh(['images', 'category', 'variants.attributeOptions.attribute']),
        ]);
    }

    // ── Delete ─────────────────────────────────────────────────────────────────

    public function destroy(Request $request, $id)
    {
        $seller  = $request->user();
        $product = $seller->products()->findOrFail($id);
        $pid     = $product->id;
        $pname   = $product->name;

        foreach ($product->images as $img) {
            Storage::disk('public')->delete($img->image_path);
        }
        $product->delete();

        $this->notifyAdmins('deleted', (object)['id' => $pid, 'name' => $pname], $seller);

        return response()->json(['success' => true, 'message' => 'Product deleted.']);
    }

    // ── Image endpoints ─────────────────────────────────────────────────────────

    public function setPrimaryImage(Request $request, $productId, $imageId)
    {
        $product = $request->user()->products()->findOrFail($productId);
        $product->images()->update(['is_primary' => false]);
        $product->images()->where('id', $imageId)->update(['is_primary' => true]);
        return response()->json(['success' => true, 'message' => 'Primary image updated.']);
    }

    public function destroyImage(Request $request, $productId, $imageId)
    {
        $product = $request->user()->products()->findOrFail($productId);

        if ($product->images()->count() <= 1) {
            return response()->json(['success' => false, 'message' => 'Cannot delete the last image.'], 400);
        }

        $image      = $product->images()->findOrFail($imageId);
        $wasPrimary = $image->is_primary;

        Storage::disk('public')->delete($image->image_path);
        $image->delete();

        if ($wasPrimary) {
            $product->images()->first()?->update(['is_primary' => true]);
        }

        return response()->json(['success' => true, 'message' => 'Image deleted.']);
    }

    // ── Private helpers ─────────────────────────────────────────────────────────

    private function saveAttributes(Product $product, Request $request): void
    {
        $attrs = $request->input('attributes', []);
        if (empty($attrs) || !is_array($attrs)) return;

        foreach ($attrs as $slug => $value) {
            $attr = \App\Models\Attribute::where('slug', $slug)->first();
            if (!$attr) continue;

            $raw = in_array($attr->type, ['select', 'multiselect', 'color'])
                ? json_encode((array) $value)
                : (string) $value;

            $product->attributeValues()->updateOrCreate(
                ['attribute_id' => $attr->id],
                ['value'        => $raw]
            );
        }
    }

    /**
     * Save / update / delete variants for a product.
     *
     * FIX: When a variant row arrives WITHOUT an id (new variant in edit mode,
     * or a re-submission after a previous save that lost the id), we now try to
     * find an EXISTING variant whose option set exactly matches the incoming
     * option_ids before creating a new one.  This prevents phantom duplicates
     * being inserted on every "Save Changes" click.
     *
     * Match logic:
     *   1. If row has an id  → find by (product_id, id)  [unchanged]
     *   2. If row has no id  → find existing variant whose sorted option IDs
     *                          exactly equal the sorted incoming option IDs
     *   3. If still nothing  → create new variant  [unchanged]
     */
    private function saveVariants(Product $product, Request $request): void
    {
        $variantsInput = $request->input('variants', []);
        if (empty($variantsInput) || !is_array($variantsInput)) return;

        // ── Pre-load all existing variants with their options once ──────────
        // This avoids N+1 queries inside the loop and makes the find-by-option
        // lookup fast even for products with many variants.
        $existingVariants = $product->variants()
            ->with('attributeOptions:id')
            ->get();

        $keepIds = [];

        foreach ($variantsInput as $row) {
            if (!is_array($row)) continue;

            $optionIds = array_values(array_filter(
                array_map('intval', (array) ($row['option_ids'] ?? [])),
                fn($id) => $id > 0
            ));
            if (empty($optionIds)) continue;

            $stock         = (int) ($row['stock'] ?? 0);
            $sku           = !empty($row['sku']) ? (string) $row['sku'] : null;
            $isActive      = filter_var($row['is_active'] ?? '1', FILTER_VALIDATE_BOOLEAN);
            $priceOverride = isset($row['price_override']) && $row['price_override'] !== '' && $row['price_override'] !== null
                ? (float) $row['price_override']
                : null;

            // ── Step 1: try to find by explicit id ─────────────────────────
            $existingId = isset($row['id']) && $row['id'] ? (int) $row['id'] : null;
            $variant    = $existingId
                ? $existingVariants->firstWhere('id', $existingId)
                : null;

            // ── Step 2: find by matching option set (no id provided) ────────
            if (!$variant) {
                $sortedIncoming = $optionIds;
                sort($sortedIncoming);
                $count = count($sortedIncoming);

                $variant = $existingVariants->first(function ($v) use ($sortedIncoming, $count) {
                    // attributeOptions was eager-loaded with only id selected
                    $vIds = $v->attributeOptions->pluck('id')->sort()->values()->toArray();
                    return count($vIds) === $count && $vIds === $sortedIncoming;
                });
            }

            // ── Step 3: update existing or create new ──────────────────────
            if ($variant) {
                $variant->update([
                    'stock'          => $stock,
                    'price_override' => $priceOverride,
                    'sku'            => $sku,
                    'is_active'      => $isActive,
                ]);
            } else {
                $variant = ProductVariant::create([
                    'product_id'     => $product->id,
                    'stock'          => $stock,
                    'price_override' => $priceOverride,
                    'sku'            => $sku,
                    'is_active'      => $isActive,
                ]);
            }

            $variant->attributeOptions()->sync($optionIds);
            $keepIds[] = $variant->id;
        }

        // Delete variants that were removed by the seller
        if (!empty($keepIds)) {
            $product->variants()->whereNotIn('id', $keepIds)->delete();
        }
    }

    private function saveGeneralImages(Product $product, Request $request): void
    {
        if (!$request->hasFile('images')) return;

        $maxOrder = $product->images()->max('order') ?? -1;
        $isFirst  = $product->images()->count() === 0;

        foreach ((array) $request->file('images') as $i => $file) {
            if (!$file || !$file->isValid()) continue;

            $path = $file->store('products', 'public');

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $path,
                'order'      => $maxOrder + $i + 1,
                'is_primary' => $isFirst && $i === 0,
            ]);
        }
    }

    /**
     * Save color-group images.
     *
     * Frontend sends:  color_images[{groupKey}][j] = File
     * where groupKey is the sorted color option IDs joined by "|", e.g. "101|102".
     *
     * We store each image with color_option_id = primaryColorOptionId (lowest id
     * in the group).  ProductController::show() rebuilds the group key from the
     * colorGroupMap and re-exposes images under both keys for backward compat.
     *
     * On UPDATE: we delete all existing images for the primaryColorOptionId before
     * saving the new batch — so new uploads always replace old ones cleanly.
     */
    private function saveColorImages(Product $product, Request $request): void
    {
        $allFiles = $request->allFiles();
        if (empty($allFiles['color_images'])) return;

        $colorImagesInput = $allFiles['color_images'];
        if (empty($colorImagesInput) || !is_array($colorImagesInput)) return;

        $maxOrder   = $product->images()->max('order') ?? -1;
        $hasPrimary = $product->images()->where('is_primary', true)->exists();
        $orderIdx   = 0;

        foreach ($colorImagesInput as $groupKey => $files) {
            // groupKey is a "|"-joined sorted list of color option IDs, e.g. "101|102"
            // Parse out all IDs; use the first (lowest) as the storage key.
            $colorOptionIds = array_values(array_filter(
                array_map('intval', explode('_', (string) $groupKey)),
                fn($id) => $id > 0
            ));

            if (empty($colorOptionIds)) {
                Log::warning("[SellerProduct::saveColorImages] Invalid groupKey: {$groupKey}");
                continue;
            }

            $primaryColorOptionId = $colorOptionIds[0];   // lowest sorted id

            // Delete existing images for this color group before saving new ones
            // (handles the update scenario — new upload replaces old)
            $product->images()
                ->where('color_option_id', $primaryColorOptionId)
                ->get()
                ->each(function ($old) {
                    Storage::disk('public')->delete($old->image_path);
                    $old->delete();
                });

            foreach ((array) $files as $file) {
                if (!$file || !method_exists($file, 'isValid') || !$file->isValid()) continue;

                $path       = $file->store('products', 'public');
                $setPrimary = !$hasPrimary && $orderIdx === 0;

                ProductImage::create([
                    'product_id'      => $product->id,
                    'variant_id'      => null,                 // group-level, not variant-specific
                    'color_option_id' => $primaryColorOptionId,
                    'image_path'      => $path,
                    'order'           => $maxOrder + $orderIdx + 1,
                    'is_primary'      => $setPrimary,
                ]);

                if ($setPrimary) $hasPrimary = true;
                $orderIdx++;
            }
        }
    }

    private function uniqueSlug(string $base, ?int $excludeId = null): string
    {
        $slug     = Str::slug($base);
        $original = $slug;
        $counter  = 2;

        while (true) {
            $query = \Illuminate\Support\Facades\DB::table('products')->where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
            if (!$query->exists()) break;
            $slug = $original . '-' . $counter++;
        }

        return $slug;
    }

    private function notifyAdmins(string $action, $product, $seller): void
    {
        try {
            $notificationClass = 'App\\Notifications\\ProductActionNotification';
            if (!class_exists($notificationClass)) return;

            $admins = \App\Models\User::where('role', 'admin')
                ->where('is_active', true)
                ->get();

            if ($admins->isEmpty()) return;

            \Illuminate\Support\Facades\Notification::send(
                $admins,
                new $notificationClass(
                    $action,
                    $product->id,
                    $product->name,
                    $seller->name,
                    $seller->id
                )
            );
        } catch (\Throwable $e) {
            Log::error('[SellerProduct] Notification failed: ' . $e->getMessage());
        }
    }
}