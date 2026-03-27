<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SellerProductController extends Controller
{
    /**
     * GET /api/seller/products
     */
    public function index(Request $request)
    {
        $seller = $request->user();

        $query = $seller->products()->with([
            'category:id,name,slug',
            'subcategory:id,name,slug',
            'primaryImage',
            'variants',  // needed for has_variants + variant_stock on list
        ]);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
            );
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('is_approved')) {
            $query->where('is_approved', filter_var($request->is_approved, FILTER_VALIDATE_BOOLEAN));
        }

        $query->orderByDesc('created_at');

        $products = $query->paginate((int) $request->input('per_page', 12));

        $products->getCollection()->transform(function ($p) {
            $p->primary_image_url = $p->primaryImage
                ? Storage::url($p->primaryImage->image_path)
                : null;
            $p->has_variants   = $p->variants->isNotEmpty();
            $p->variant_stock  = $p->variants->sum('stock');
            return $p;
        });

        return response()->json(['success' => true, 'data' => $products]);
    }

    /**
     * GET /api/seller/products/stats
     */
    public function stats(Request $request)
    {
        $seller = $request->user();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'        => $seller->products()->count(),
                'approved'     => $seller->products()->where('is_approved', true)->count(),
                'pending'      => $seller->products()->where('is_approved', false)->count(),
                'active'       => $seller->products()->where('is_active', true)->count(),
                'total_stock'  => (int) $seller->products()->sum('stock'),
                'out_of_stock' => $seller->products()->where('stock', 0)->count(),
                'total_views'  => (int) $seller->products()->sum('views'),
            ],
        ]);
    }

    /**
     * GET /api/seller/products/{id}
     */
    public function show(Request $request, int $id)
    {
        $product = Product::where('id', $id)
            ->where('seller_id', $request->user()->id)
            ->with([
                'category:id,name,slug',
                'subcategory:id,name,slug',
                'images',
                'primaryImage',
                'attributeValues.attribute',
                'variants.attributeOptions.attribute',
            ])
            ->firstOrFail();

        $product->primary_image_url = $product->primaryImage
            ? Storage::url($product->primaryImage->image_path)
            : null;

        $product->images->each(fn($img) => $img->url = Storage::url($img->image_path));

        $product->existing_attributes = $product->getAttributeValuesForForm();

        $product->variant_rows = $product->variants->map(fn($v) => [
            'id'             => $v->id,
            'option_ids'     => $v->attributeOptions->pluck('id')->toArray(),
            'stock'          => $v->stock,
            'price_override' => $v->price_override,
            'sku'            => $v->sku,
            'label'          => $v->label,
            'option_map'     => $v->option_map,
        ])->values();

        return response()->json(['success' => true, 'data' => $product]);
    }

    /**
     * POST /api/seller/products
     *
     * Accepts multipart/form-data with PHP-style array notation for variants:
     *   variants[0][option_ids][0] = 3
     *   variants[0][stock]         = 10
     *   variants[0][is_active]     = 1
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                         => 'required|string|max:255',
            'slug'                         => 'nullable|string|max:255|unique:products,slug',
            'sku'                          => 'nullable|string|unique:products,sku',
            'description'                  => 'nullable|string',
            'short_description'            => 'nullable|string|max:500',
            'price'                        => 'required|numeric|min:0',
            'stock'                        => 'required|integer|min:0',
            'category_id'                  => 'required|exists:categories,id',
            'subcategory_id'               => 'nullable|exists:subcategories,id',
            'is_active'                    => 'sometimes',
            'images'                       => 'nullable|array|max:8',
            'images.*'                     => 'image|mimes:jpeg,png,jpg,webp|max:5120',
            'attributes'                   => 'nullable|array',
            'variants'                     => 'nullable|array',
            'variants.*.id'                => 'nullable|integer|exists:product_variants,id',
            'variants.*.option_ids'        => 'required_with:variants|array',
            'variants.*.option_ids.*'      => 'integer|exists:attribute_options,id',
            'variants.*.stock'             => 'required_with:variants|integer|min:0',
            'variants.*.price_override'    => 'nullable|numeric|min:0',
            'variants.*.sku'               => 'nullable|string',
            'variants.*.is_active'         => 'sometimes',
        ]);

        $isActive = filter_var($request->input('is_active', true), FILTER_VALIDATE_BOOLEAN);

        $product = $request->user()->products()->create([
            'name'              => $validated['name'],
            'slug'              => $validated['slug'] ?? Str::slug($validated['name']),
            'sku'               => $validated['sku']               ?? null,
            'description'       => $validated['description']       ?? null,
            'short_description' => $validated['short_description'] ?? null,
            'price'             => $validated['price'],
            'stock'             => $validated['stock'],
            'category_id'       => $validated['category_id'],
            'subcategory_id'    => $validated['subcategory_id']    ?? null,
            'is_active'         => $isActive,
            'is_approved'       => false,
            'featured'          => false,
            'views'             => 0,
        ]);

        // ── Images ────────────────────────────────────────────────────────
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products', 'public');
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_path' => $path,
                    'order'      => $index,
                    'is_primary' => $index === 0,
                ]);
            }
        }

        // ── Product-level attributes ───────────────────────────────────────
        if (!empty($validated['attributes'])) {
            $product->syncAttributeValues($validated['attributes']);
        }

        // ── Variants ──────────────────────────────────────────────────────
        if (!empty($validated['variants'])) {
            $this->saveVariants($product, $validated['variants']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product created! It will be reviewed by an admin.',
            'data'    => $product->load(['images', 'category', 'variants.attributeOptions.attribute']),
        ], 201);
    }

    /**
     * PUT /api/seller/products/{id}
     * (also handles POST with _method=PUT for FormData uploads)
     */
    public function update(Request $request, int $id)
    {
        $product = Product::where('id', $id)
            ->where('seller_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'name'                         => 'required|string|max:255',
            'slug'                         => 'nullable|string|max:255|unique:products,slug,' . $product->id,
            'sku'                          => 'nullable|string|unique:products,sku,' . $product->id,
            'description'                  => 'nullable|string',
            'short_description'            => 'nullable|string|max:500',
            'price'                        => 'required|numeric|min:0',
            'stock'                        => 'required|integer|min:0',
            'category_id'                  => 'required|exists:categories,id',
            'subcategory_id'               => 'nullable|exists:subcategories,id',
            'is_active'                    => 'sometimes',
            'images'                       => 'nullable|array|max:8',
            'images.*'                     => 'image|mimes:jpeg,png,jpg,webp|max:5120',
            'delete_image_ids'             => 'nullable|array',
            'delete_image_ids.*'           => 'integer',
            'attributes'                   => 'nullable|array',
            'variants'                     => 'nullable|array',
            'variants.*.id'                => 'nullable|integer',
            'variants.*.option_ids'        => 'required_with:variants|array',
            'variants.*.option_ids.*'      => 'integer|exists:attribute_options,id',
            'variants.*.stock'             => 'required_with:variants|integer|min:0',
            'variants.*.price_override'    => 'nullable|numeric|min:0',
            'variants.*.sku'               => 'nullable|string',
            'variants.*.is_active'         => 'sometimes',
        ]);

        $isActive = filter_var($request->input('is_active', $product->is_active), FILTER_VALIDATE_BOOLEAN);

        $product->update([
            'name'              => $validated['name'],
            'slug'              => $validated['slug'] ?? Str::slug($validated['name']),
            'sku'               => $validated['sku']               ?? $product->sku,
            'description'       => $validated['description']       ?? $product->description,
            'short_description' => $validated['short_description'] ?? $product->short_description,
            'price'             => $validated['price'],
            'stock'             => $validated['stock'],
            'category_id'       => $validated['category_id'],
            'subcategory_id'    => $validated['subcategory_id']    ?? null,
            'is_active'         => $isActive,
        ]);

        // ── Delete images ──────────────────────────────────────────────────
        if (!empty($validated['delete_image_ids'])) {
            $toDelete = $product->images()->whereIn('id', $validated['delete_image_ids'])->get();
            foreach ($toDelete as $img) {
                Storage::disk('public')->delete($img->image_path);
                $img->delete();
            }
        }

        // ── Upload new images ──────────────────────────────────────────────
        if ($request->hasFile('images')) {
            $maxOrder = $product->images()->max('order') ?? -1;
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products', 'public');
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_path' => $path,
                    'order'      => $maxOrder + $index + 1,
                    'is_primary' => false,
                ]);
            }
        }

        // ── Product-level attributes ───────────────────────────────────────
        if (isset($validated['attributes'])) {
            $product->syncAttributeValues($validated['attributes']);
        }

        // ── Variants ──────────────────────────────────────────────────────
        if (isset($validated['variants'])) {
            $this->saveVariants($product, $validated['variants']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product updated.',
            'data'    => $product->fresh(['images', 'category', 'variants.attributeOptions.attribute']),
        ]);
    }

    /**
     * DELETE /api/seller/products/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $product = Product::where('id', $id)
            ->where('seller_id', $request->user()->id)
            ->firstOrFail();

        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        $product->delete();

        return response()->json(['success' => true, 'message' => 'Product deleted.']);
    }

    /**
     * DELETE /api/seller/products/{id}/images/{imageId}
     */
    public function destroyImage(Request $request, int $id, int $imageId)
    {
        $product = Product::where('id', $id)
            ->where('seller_id', $request->user()->id)
            ->firstOrFail();

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

    /**
     * PATCH /api/seller/products/{id}/images/{imageId}/primary
     */
    public function setPrimaryImage(Request $request, int $id, int $imageId)
    {
        $product = Product::where('id', $id)
            ->where('seller_id', $request->user()->id)
            ->firstOrFail();

        $product->images()->update(['is_primary' => false]);
        $product->images()->where('id', $imageId)->update(['is_primary' => true]);

        return response()->json(['success' => true, 'message' => 'Primary image updated.']);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Save variants for a product from the request's variants array.
     * Handles both create (no id) and update (with id).
     *
     * $variantsData shape (from FormData PHP array parsing):
     * [
     *   ['option_ids' => ['3', '7'], 'stock' => '10', 'price_override' => '', 'is_active' => '1'],
     *   ...
     * ]
     */
    private function saveVariants(Product $product, array $variantsData): void
    {
        // IDs present in incoming data
        $incomingIds = collect($variantsData)
            ->pluck('id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();

        // Delete variants not in the new list
        if (!empty($incomingIds)) {
            $product->variants()->whereNotIn('id', $incomingIds)->delete();
        } else {
            $product->variants()->delete();
        }

        foreach ($variantsData as $vData) {
            $existingId = isset($vData['id']) && $vData['id'] ? (int) $vData['id'] : null;

            $variant = $existingId
                ? ProductVariant::find($existingId)
                : new ProductVariant(['product_id' => $product->id]);

            if (!$variant) continue;

            // Cast is_active: "1"/"0"/true/false all handled
            $active = isset($vData['is_active'])
                ? filter_var($vData['is_active'], FILTER_VALIDATE_BOOLEAN)
                : true;

            // Cast price_override: empty string → null
            $priceOverride = isset($vData['price_override']) && $vData['price_override'] !== ''
                ? (float) $vData['price_override']
                : null;

            $variant->fill([
                'product_id'     => $product->id,
                'sku'            => $vData['sku']   ?? null,
                'price_override' => $priceOverride,
                'stock'          => (int) ($vData['stock'] ?? 0),
                'is_active'      => $active,
            ])->save();

            // Sync attribute options
            if (isset($vData['option_ids']) && is_array($vData['option_ids'])) {
                $optionIds = array_values(
                    array_filter(
                        array_map('intval', $vData['option_ids']),
                        fn($id) => $id > 0
                    )
                );
                $variant->attributeOptions()->sync($optionIds);
            }
        }
    }
}