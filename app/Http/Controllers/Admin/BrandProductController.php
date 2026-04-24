<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Helpers\PlatformUser;
/**
 * AdminBrandProductController
 *
 * Manages CHOOSE'Tounsi brand products (is_platform_product = true).
 * Architecture mirrors SellerProductController exactly — full variants,
 * color group images, variant images, attributes — but:
 *   - No seller_id (null for platform products)
 *   - No approval flow (admin owns these, they go live immediately)
 *   - is_approved = true, is_platform_product = true always set on create
 *
 * Routes (inside auth:sanctum + role:admin):
 *   GET    /api/admin/brand-products
 *   POST   /api/admin/brand-products
 *   GET    /api/admin/brand-products/{id}
 *   PUT    /api/admin/brand-products/{id}   (or POST with _method=PUT for FormData)
 *   DELETE /api/admin/brand-products/{id}
 *   DELETE /api/admin/brand-products/{id}/images/{imageId}
 *   PATCH  /api/admin/brand-products/{id}/images/{imageId}/primary
 */
class BrandProductController extends Controller
{
    // ── Index ──────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = Product::platform()->with([
            'category:id,name,slug',
            'subcategory:id,name,slug',
            'primaryImage',
        ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('name', 'like', "%$s%")
                 ->orWhere('sku', 'like', "%$s%")
            );
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('featured')) {
            $query->where('featured', filter_var($request->featured, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 15));

        $products->getCollection()->transform(function ($p) {
            $p->primary_image_url = $p->primaryImage
                ? Storage::url($p->primaryImage->image_path)
                : null;
            $p->has_variants  = $p->variants()->exists();
            $p->variant_stock = (int) $p->variants()->sum('stock');
            return $p;
        });

        return response()->json(['success' => true, 'data' => $products]);
    }

    // ── Show ───────────────────────────────────────────────────────────────────

    public function show($id)
    {
        $product = Product::platform()
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

        // Build variant_rows identical to SellerProductController@show
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
            foreach ($nonColorMap as $s => $entry) {
                $optionMap[$s] = $entry;
            }

            $labelParts = [];
            if (isset($optionMap['color'])) $labelParts[] = $optionMap['color']['value'];
            foreach ($optionMap as $s => $entry) {
                if ($s !== 'color') $labelParts[] = $entry['value'];
            }
            $label = implode(' / ', array_filter($labelParts));

            $imageUrls = $v->relationLoaded('images')
                ? $v->images->map(fn($i) => Storage::url($i->image_path))->toArray()
                : [];

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

        // Expose has_variants + variant_stock for the frontend
        $product->has_variants  = $product->variants->isNotEmpty();
        $product->variant_stock = (int) $product->variants->sum('stock');

        return response()->json(['success' => true, 'data' => $product]);
    }

    // ── Stats ──────────────────────────────────────────────────────────────────

    public function stats()
    {
        $base = Product::platform();
        return response()->json([
            'success' => true,
            'data'    => [
                'total'        => $base->count(),
                'active'       => (clone $base)->where('is_active', true)->count(),
                'featured'     => (clone $base)->where('featured', true)->count(),
                'total_stock'  => (int) (clone $base)->sum('stock'),
                'out_of_stock' => (int) (clone $base)->where('stock', 0)->count(),
                'total_views'  => (int) (clone $base)->sum('views'),
            ],
        ]);
    }

    // ── Store ──────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name'        => 'required|string|max:255',
                'price'       => 'required|numeric|min:0',
                'stock'       => 'required|integer|min:0',
                'category_id' => 'required|exists:categories,id',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'errors'  => $e->validator->errors() ?? [],
                'message' => $e->getMessage(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $product = Product::create([
                'seller_id'            => PlatformUser::id(), // platform seller owns this product                'is_platform_product'  => true,
                'is_approved'          => true,           // no approval flow
                'is_active'            => filter_var($request->input('is_active', true), FILTER_VALIDATE_BOOLEAN),
                'featured'             => filter_var($request->input('featured', false), FILTER_VALIDATE_BOOLEAN),
                'name'                 => $request->name,
                'slug'                 => $this->uniqueSlug($request->slug ?: $request->name),
                'sku'                  => $request->sku ?: null,
                'description'          => $request->description ?? null,
                'short_description'    => $request->short_description ?? null,
                'price'                => $request->price,
                'stock'                => $request->stock,
                'category_id'          => $request->category_id,
                'subcategory_id'       => $request->subcategory_id ?: null,
                'views'                => 0,
            ]);

            $this->saveAttributes($product, $request);
            $this->saveVariants($product, $request);

            if (!empty($request->input('variants', []))) {
                $product->fresh()->syncActiveStatusFromVariants();
            }

            $this->saveGeneralImages($product, $request);
            $this->saveColorImages($product, $request);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[AdminBrandProduct::store] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Brand product created.',
            'data'    => $product->load(['images', 'category', 'variants.attributeOptions.attribute']),
        ], 201);
    }

    // ── Update ─────────────────────────────────────────────────────────────────

    public function update(Request $request, $id)
    {
        $product = Product::platform()->findOrFail($id);

        DB::beginTransaction();
        try {
            $isActive = $request->has('is_active')
                ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                : $product->is_active;

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
                'featured'          => $request->has('featured')
                    ? filter_var($request->featured, FILTER_VALIDATE_BOOLEAN)
                    : $product->featured,
            ]);

            $this->saveAttributes($product, $request);
            $this->saveVariants($product, $request);

            if (!empty($request->input('variants', []))) {
                $product->fresh()->syncActiveStatusFromVariants();
            }

            // Delete flagged images (product-level + variant-level merged)
            if ($deleteIds = $request->input('delete_image_ids', [])) {
                foreach ($product->images()->whereIn('id', (array) $deleteIds)->get() as $img) {
                    Storage::disk('public')->delete($img->image_path);
                    $img->delete();
                }
            }

            $this->saveGeneralImages($product, $request);
            $this->saveColorImages($product, $request);
            $this->saveVariantImages($product, $request);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[AdminBrandProduct::update] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Brand product updated.',
            'data'    => $product->fresh(['images', 'category', 'variants.attributeOptions.attribute']),
        ]);
    }

    // ── Destroy ────────────────────────────────────────────────────────────────

    public function destroy($id)
    {
        $product = Product::platform()->findOrFail($id);
        $product->delete(); // boot() handles image cleanup
        return response()->json(['success' => true, 'message' => 'Brand product deleted.']);
    }

    // ── Image endpoints ────────────────────────────────────────────────────────

    public function setPrimaryImage($productId, $imageId)
    {
        $product = Product::platform()->findOrFail($productId);
        $product->images()->update(['is_primary' => false]);
        $product->images()->where('id', $imageId)->update(['is_primary' => true]);
        return response()->json(['success' => true, 'message' => 'Primary image updated.']);
    }

    public function destroyImage($productId, $imageId)
    {
        $product = Product::platform()->findOrFail($productId);

        if ($product->images()->count() <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete the last image.',
            ], 400);
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

    // ── Private helpers — identical to SellerProductController ────────────────

    private function saveAttributes(Product $product, Request $request): void
    {
        $attrs = $request->input('attributes', []);
        if (empty($attrs) || !is_array($attrs)) return;

        foreach ($attrs as $slug => $value) {
            $attr = Attribute::where('slug', $slug)->first();
            if (!$attr) continue;
            $raw = in_array($attr->type, ['select', 'multiselect', 'color'])
                ? json_encode((array) $value)
                : (string) $value;
            $product->attributeValues()->updateOrCreate(
                ['attribute_id' => $attr->id],
                ['value' => $raw]
            );
        }
    }

    private function saveVariants(Product $product, Request $request): void
    {
        $variantsInput = $request->input('variants', []);
        if (empty($variantsInput) || !is_array($variantsInput)) return;

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

            $existingId = isset($row['id']) && $row['id'] ? (int) $row['id'] : null;
            $variant    = $existingId
                ? $existingVariants->firstWhere('id', $existingId)
                : null;

            if (!$variant) {
                $sortedIncoming = $optionIds;
                sort($sortedIncoming);
                $count = count($sortedIncoming);
                $variant = $existingVariants->first(function ($v) use ($sortedIncoming, $count) {
                    $vIds = $v->attributeOptions->pluck('id')->sort()->values()->toArray();
                    return count($vIds) === $count && $vIds === $sortedIncoming;
                });
            }

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
            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $file->store('products', 'public'),
                'order'      => $maxOrder + $i + 1,
                'is_primary' => $isFirst && $i === 0,
            ]);
        }
    }

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
            $colorOptionIds = array_values(array_filter(
                array_map('intval', explode('_', (string) $groupKey)),
                fn($id) => $id > 0
            ));
            if (empty($colorOptionIds)) continue;

            $primaryColorOptionId = $colorOptionIds[0];

            $product->images()
                ->where('color_option_id', $primaryColorOptionId)
                ->get()
                ->each(function ($old) {
                    Storage::disk('public')->delete($old->image_path);
                    $old->delete();
                });

            foreach ((array) $files as $file) {
                if (!$file || !method_exists($file, 'isValid') || !$file->isValid()) continue;
                $setPrimary = !$hasPrimary && $orderIdx === 0;
                ProductImage::create([
                    'product_id'      => $product->id,
                    'variant_id'      => null,
                    'color_option_id' => $primaryColorOptionId,
                    'image_path'      => $file->store('products', 'public'),
                    'order'           => $maxOrder + $orderIdx + 1,
                    'is_primary'      => $setPrimary,
                ]);
                if ($setPrimary) $hasPrimary = true;
                $orderIdx++;
            }
        }
    }

    private function saveVariantImages(Product $product, Request $request): void
    {
        $allFiles = $request->allFiles();
        if (empty($allFiles['variant_images'])) return;
        $variantImagesInput = $allFiles['variant_images'];
        if (!is_array($variantImagesInput)) return;

        $validVariantIds = $product->variants()->pluck('id')->flip();
        $maxOrder        = $product->images()->max('order') ?? -1;
        $orderIdx        = 0;

        foreach ($variantImagesInput as $variantIdStr => $files) {
            $variantId = (int) $variantIdStr;
            if (!isset($validVariantIds[$variantId])) continue;

            foreach ((array) $files as $file) {
                if (!$file || !method_exists($file, 'isValid') || !$file->isValid()) continue;
                ProductImage::create([
                    'product_id'      => $product->id,
                    'variant_id'      => $variantId,
                    'color_option_id' => null,
                    'image_path'      => $file->store('products', 'public'),
                    'order'           => $maxOrder + $orderIdx + 1,
                    'is_primary'      => false,
                ]);
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
            $query = DB::table('products')->where('slug', $slug);
            if ($excludeId) $query->where('id', '!=', $excludeId);
            if (!$query->exists()) break;
            $slug = $original . '-' . $counter++;
        }
        return $slug;
    }
}