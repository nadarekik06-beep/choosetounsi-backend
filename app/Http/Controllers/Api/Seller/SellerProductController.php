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
            'images',          // load ALL images (includes color_option_id ones)
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
            // ── IMAGE PRIORITY ─────────────────────────────────────────────
            // 1. Any image explicitly marked is_primary = true (works for both
            //    general images AND color images, whichever was saved first)
            // 2. First image ordered by `order` ASC (fallback, any type)
            // 3. null  →  frontend shows the ImageIcon placeholder (no broken img)
            $primaryImage = $p->images->firstWhere('is_primary', true)
                         ?? $p->images->sortBy('order')->first();

            $p->primary_image_url = $primaryImage
                ? Storage::url($primaryImage->image_path)
                : null;

            $p->has_variants  = $p->images->isNotEmpty()
                ? $p->variants()->exists()
                : $p->variants()->exists();
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
                'variants.images',
            ])
            ->findOrFail($id);

        $product->primary_image_url = $product->primaryImage
            ? Storage::url($product->primaryImage->image_path)
            : null;

        $product->images->each(fn($img) => $img->url = Storage::url($img->image_path));

        $product->existing_attributes = $product->attributeValues
            ->mapWithKeys(fn($v) => [
                $v->attribute->slug => $v->attribute->decodeValue($v->value),
            ]);

        $product->variant_rows = $product->variants->map(fn($v) => [
            'id'             => $v->id,
            'option_ids'     => $v->attributeOptions->pluck('id')->toArray(),
            'stock'          => $v->stock,
            'price_override' => $v->price_override !== null ? (string) $v->price_override : '',
            'sku'            => $v->sku ?? '',
            'is_active'      => $v->is_active,
            'image_urls'     => $v->relationLoaded('images')
                ? $v->images->map(fn($i) => Storage::url($i->image_path))->toArray()
                : [],
        ]);

        return response()->json(['success' => true, 'data' => $product]);
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

        // ── Guarantee at least one image is marked primary ─────────────────
        // Covers the case where color images were saved but none got is_primary
        // (e.g. a race condition or re-save scenario)
        $this->ensurePrimaryImage($product);

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

        // ── Guarantee at least one image is marked primary after all saves ──
        $this->ensurePrimaryImage($product);

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
            $product->images()->orderBy('order')->first()?->update(['is_primary' => true]);
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

    private function saveVariants(Product $product, Request $request): void
    {
        $variantsInput = $request->input('variants', []);
        if (empty($variantsInput) || !is_array($variantsInput)) return;

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

            $existingId = isset($row['id']) && $row['id'] ? (int) $row['id'] : null;
            $variant    = $existingId
                ? ProductVariant::where('product_id', $product->id)->find($existingId)
                : null;

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

<<<<<<< HEAD
    private function saveColorImages(Product $product, Request $request): void
    {
        if (!Schema::hasColumn('product_images', 'color_option_id')) {
            Log::warning('[SellerProduct] color_option_id column missing — run: php artisan migrate');
            return;
        }

        if (!$request->hasFile('color_images')) return;

        $colorImagesInput = $request->file('color_images', []);
        if (empty($colorImagesInput) || !is_array($colorImagesInput)) return;

        $maxOrder   = $product->images()->max('order') ?? -1;
        $hasPrimary = $product->images()->where('is_primary', true)->exists();
        $orderIdx   = 0;

        foreach ($colorImagesInput as $colorOptionId => $files) {
            $colorOptionId = (int) $colorOptionId;
            $colorOption   = AttributeOption::find($colorOptionId);
            if (!$colorOption) continue;

            foreach ($product->images()->where('color_option_id', $colorOptionId)->get() as $old) {
                Storage::disk('public')->delete($old->image_path);
                $old->delete();
            }

            $variantId = DB::table('variant_attribute_values as vav')
                ->join('product_variants as pv', 'pv.id', '=', 'vav.variant_id')
                ->where('pv.product_id', $product->id)
                ->where('vav.attribute_option_id', $colorOptionId)
                ->value('pv.id');

            foreach ((array) $files as $j => $file) {
                if (!$file || !method_exists($file, 'isValid') || !$file->isValid()) continue;

                $path = $file->store('products', 'public');

                // Mark the very first color image as primary when no primary exists yet.
                // This ensures color-only products always have a thumbnail in the listing.
                $setPrimary = !$hasPrimary && $orderIdx === 0;

                ProductImage::create([
                    'product_id'      => $product->id,
                    'variant_id'      => $variantId ?: null,
                    'color_option_id' => $colorOptionId,
                    'image_path'      => $path,
                    'order'           => $maxOrder + $orderIdx + 1,
                    'is_primary'      => $setPrimary,
                ]);

                if ($setPrimary) $hasPrimary = true;
                $orderIdx++;
            }
        }
=======
   private function saveColorImages(Product $product, Request $request): void
{
    if (!Schema::hasColumn('product_images', 'color_option_id')) {
        Log::warning('[SellerProduct] color_option_id column missing — run: php artisan migrate');
        return;
>>>>>>> b06fc03 (Abdou's changes)
    }

    // ── FIX: hasFile() fails on nested arrays — use getAllFiles() instead ──
    $allFiles = $request->allFiles();
    if (empty($allFiles['color_images'])) return;

    $colorImagesInput = $allFiles['color_images'];
    if (empty($colorImagesInput) || !is_array($colorImagesInput)) return;

    $maxOrder   = $product->images()->max('order') ?? -1;
    $hasPrimary = $product->images()->where('is_primary', true)->exists();
    $orderIdx   = 0;

    foreach ($colorImagesInput as $colorOptionId => $files) {
        $colorOptionId = (int) $colorOptionId;
        $colorOption   = AttributeOption::find($colorOptionId);
        if (!$colorOption) continue;

        // Delete old images for this color before saving new ones
        foreach ($product->images()->where('color_option_id', $colorOptionId)->get() as $old) {
            Storage::disk('public')->delete($old->image_path);
            $old->delete();
        }

        // Find the variant linked to this color option
        $variantId = DB::table('variant_attribute_values as vav')
            ->join('product_variants as pv', 'pv.id', '=', 'vav.variant_id')
            ->where('pv.product_id', $product->id)
            ->where('vav.attribute_option_id', $colorOptionId)
            ->value('pv.id');

        foreach ((array) $files as $j => $file) {
            if (!$file || !method_exists($file, 'isValid') || !$file->isValid()) continue;

            $path = $file->store('products', 'public');

            $setPrimary = !$hasPrimary && $orderIdx === 0;

            ProductImage::create([
                'product_id'      => $product->id,
                'variant_id'      => $variantId ?: null,
                'color_option_id' => $colorOptionId,
                'image_path'      => $path,
                'order'           => $maxOrder + $orderIdx + 1,
                'is_primary'      => $setPrimary,
            ]);

            if ($setPrimary) $hasPrimary = true;
            $orderIdx++;
        }
    }
}
    /**
     * Guarantee that exactly one product image has is_primary = true.
     * Called after all image-save operations complete.
     * Safe to call multiple times — only acts when no primary exists.
     */
    private function ensurePrimaryImage(Product $product): void
    {
        $hasPrimary = $product->images()->where('is_primary', true)->exists();
        if (!$hasPrimary) {
            $first = $product->images()->orderBy('order')->orderBy('id')->first();
            if ($first) {
                $first->update(['is_primary' => true]);
                Log::info('[SellerProduct] ensurePrimaryImage: promoted image #' . $first->id . ' for product #' . $product->id);
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