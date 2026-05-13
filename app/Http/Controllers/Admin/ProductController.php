<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductAttributeValue;
use App\Models\Attribute;
use App\Notifications\ProductReviewedNotification;
use App\Notifications\ProductActionNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with([
            'seller:id,name,email',
            'category:id,name',
            'primaryImage',
        ]);

        $status = $request->query('status', 'pending');
        if ($status === 'pending')      $query->where('is_approved', false);
        elseif ($status === 'approved') $query->where('is_approved', true)->where('is_active', true);
        elseif ($status === 'disabled') $query->where('is_approved', true)->where('is_active', false);

        if ($search = $request->query('search'))
            $query->where('name', 'like', '%' . $search . '%');
        if ($categoryId = $request->query('category_id'))
            $query->where('category_id', $categoryId);
        if ($sellerId = $request->query('seller_id'))
            $query->where('seller_id', $sellerId);

        $products = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15));

        $products->getCollection()->transform(function ($product) {
            $product->status = $this->deriveStatus($product);
            $product->primary_image_url = $product->primaryImage
                ? Storage::disk('public')->url($product->primaryImage->image_path)
                : null;
            return $product;
        });

        return response()->json(['success' => true, 'data' => $products]);
    }

    public function show($id)
    {
        $product = Product::with([
            'seller:id,name,email',
            'category:id,name,slug',
            'subcategory:id,name,slug,category_id',
            'images',
            'primaryImage',
            'attributeValues.attribute',
            'variants' => fn($q) => $q->with([
                'attributeOptions.attribute:id,slug,name,type',
                'images',
            ]),
        ])->findOrFail($id);

        $product->status = $this->deriveStatus($product);

        $product->primary_image_url = $product->primaryImage
            ? Storage::disk('public')->url($product->primaryImage->image_path)
            : null;

        $product->images->each(function ($image) {
            $image->url = Storage::disk('public')->url($image->image_path);
        });

        // Build variant payload identical to SellerProductController@show
        $product->variant_rows = $product->variants->map(fn($v) => [
            'id'             => $v->id,
            'option_ids'     => $v->attributeOptions->pluck('id')->toArray(),
            'stock'          => $v->stock,
            'price_override' => $v->price_override !== null ? (string) $v->price_override : '',
            'sku'            => $v->sku ?? '',
            'is_active'      => $v->is_active,
            'image_urls'     => $v->images->map(fn($i) => Storage::disk('public')->url($i->image_path))->toArray(),
        ]);

        // Build existing_attributes identical to SellerProductController@show
        $product->existing_attributes = $product->attributeValues
            ->mapWithKeys(fn($v) => [
                $v->attribute->slug => $v->attribute->decodeValue($v->value),
            ]);

        $product->variant_data = $product->variants->map(function ($v) {
            $v->images->each(fn($i) => $i->url = Storage::disk('public')->url($i->image_path));
            return [
                'id'             => $v->id,
                'label'          => $v->label,
                'sku'            => $v->sku,
                'stock'          => $v->stock,
                'price_override' => $v->price_override,
                'is_active'      => $v->is_active,
                'option_map'     => $v->option_map,
                'image_urls'     => $v->images->map(fn($i) => $i->url)->values(),
            ];
        });

        return response()->json(['success' => true, 'data' => $product]);
    }

    /**
     * PUT /api/admin/products/{id}
     *
     * Full product edit for admin — same logic as SellerProductController@update
     * but without seller ownership check and without resetting approval status.
     */
    public function update(Request $request, $id)
    {
        $product = Product::with('seller')->findOrFail($id);

        $request->validate([
            'name'              => 'sometimes|required|string|max:255',
            'description'       => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'price'             => 'sometimes|required|numeric|min:0',
            'stock'             => 'sometimes|required|integer|min:0',
            'category_id'       => 'sometimes|required|exists:categories,id',
            'subcategory_id'    => 'nullable|exists:subcategories,id',
            'is_active'         => 'sometimes|boolean',
            'is_approved'       => 'sometimes|boolean',
            'featured'          => 'sometimes|boolean',
            'season'            => 'sometimes|nullable|string|max:30',  // ← ADD

        ]);

        // ── Scalar fields ──────────────────────────────────────────────────
        $fieldsToUpdate = [];
        foreach (['name', 'description', 'short_description', 'price', 'stock',
                  'category_id', 'subcategory_id', 'is_approved', 'featured' , 'season'] as $field) {
            if ($request->has($field)) {
                $fieldsToUpdate[$field] = $request->input($field);
            }
        }

        // is_active is NOT a free scalar field — it is derived from variants.
        // If admin tries to manually activate a product that has no active variants,
        // reject immediately with a clear explanation.
        if ($request->has('is_active') && $request->boolean('is_active')) {
            $hasVariants      = $product->variants()->exists();
            $hasActiveVariant = $product->variants()->where('is_active', true)->exists();

            if ($hasVariants && !$hasActiveVariant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot activate this product: it has no active variants. Activate at least one variant first.',
                ], 422);
            }
        }

        // Manual deactivation by admin is always allowed.
        if ($request->has('is_active') && !$request->boolean('is_active')) {
            $fieldsToUpdate['is_active'] = false;
        }

        // Null out subcategory_id when category changes and no subcategory given
        if (isset($fieldsToUpdate['category_id']) &&
            $fieldsToUpdate['category_id'] != $product->category_id &&
            !$request->has('subcategory_id')) {
            $fieldsToUpdate['subcategory_id'] = null;
        }

        if (!empty($fieldsToUpdate)) {
            $product->update($fieldsToUpdate);
        }

        // ── Attributes ─────────────────────────────────────────────────────
        if ($request->has('attributes')) {
            $attrs = $request->input('attributes', []);
            if (is_array($attrs)) {
                foreach ($attrs as $slug => $value) {
                    $attr = Attribute::where('slug', $slug)->first();
                    if (!$attr) continue;

                    $raw = in_array($attr->type, ['select', 'multiselect', 'color'])
                        ? json_encode((array) $value)
                        : (string) $value;

                    ProductAttributeValue::updateOrCreate(
                        ['product_id' => $product->id, 'attribute_id' => $attr->id],
                        ['value' => $raw]
                    );
                }
            }
        }

        // ── Variants ───────────────────────────────────────────────────────
        if ($request->has('variants')) {
            $variantsInput = $request->input('variants', []);
            if (is_array($variantsInput) && !empty($variantsInput)) {
                $keepIds = [];

                foreach ($variantsInput as $row) {
                    if (!is_array($row)) continue;

                    $optionIds = array_values(array_filter(
                        array_map('intval', (array) ($row['option_ids'] ?? [])),
                        fn($i) => $i > 0
                    ));
                    if (empty($optionIds)) continue;

                    $existingId    = isset($row['id']) && $row['id'] ? (int) $row['id'] : null;
                    $stock         = (int) ($row['stock'] ?? 0);
                    $sku           = !empty($row['sku']) ? (string) $row['sku'] : null;
                    $isActive      = filter_var($row['is_active'] ?? '1', FILTER_VALIDATE_BOOLEAN);
                    $priceOverride = isset($row['price_override']) && $row['price_override'] !== '' && $row['price_override'] !== null
                        ? (float) $row['price_override'] : null;

                    $variant = $existingId
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

            // Enforce variant→status rule after any variant change.
            $product->fresh()->syncActiveStatusFromVariants();
        }

        // ── Notify seller ──────────────────────────────────────────────────
        if ($product->seller) {
            try {
                $product->seller->notify(new ProductActionNotification(
                    'updated', $product->id, $product->name, 'Admin', 0
                ));
            } catch (\Throwable $e) {
                Log::warning('[AdminProduct::update] Notification failed: ' . $e->getMessage());
            }
        }

        // ── Reload and return ──────────────────────────────────────────────
        $product->load([
            'seller:id,name,email',
            'category:id,name,slug',
            'subcategory:id,name,slug,category_id',
            'images',
            'primaryImage',
            'attributeValues.attribute',
            'variants.attributeOptions.attribute',
            'variants.images',
        ]);

        $product->status = $this->deriveStatus($product);
        $product->primary_image_url = $product->primaryImage
            ? Storage::disk('public')->url($product->primaryImage->image_path)
            : null;
        $product->images->each(fn($i) => $i->url = Storage::disk('public')->url($i->image_path));

        $product->variant_rows = $product->variants->map(fn($v) => [
            'id'             => $v->id,
            'option_ids'     => $v->attributeOptions->pluck('id')->toArray(),
            'stock'          => $v->stock,
            'price_override' => $v->price_override !== null ? (string) $v->price_override : '',
            'sku'            => $v->sku ?? '',
            'is_active'      => $v->is_active,
        ]);

        $product->existing_attributes = $product->attributeValues
            ->mapWithKeys(fn($v) => [
                $v->attribute->slug => $v->attribute->decodeValue($v->value),
            ]);

        return response()->json(['success' => true, 'message' => 'Product updated.', 'data' => $product]);
    }

    public function approve($id)
{
    $product = Product::with('seller')->findOrFail($id);

    // Approve first, then let variant state decide is_active.
    // Never force is_active = true — a product with no active variants
    // must stay inactive even after approval.
    $product->update(['is_approved' => true]);
    $product->fresh()->syncActiveStatusFromVariants();

    if ($product->seller) {
        $product->seller->notify(new ProductReviewedNotification('approved', $product->id, $product->name));
    }
    return response()->json(['success' => true, 'message' => 'Product approved.']);
}

    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);
        $product = Product::with('seller')->findOrFail($id);
        $product->update(['is_approved' => false, 'is_active' => false]);
        if ($product->seller) {
            $product->seller->notify(new ProductReviewedNotification('rejected', $product->id, $product->name, $request->reason));
        }
        return response()->json(['success' => true, 'message' => 'Product rejected.']);
    }

    public function disable($id)
    {
        Product::findOrFail($id)->update(['is_active' => false]);
        return response()->json(['success' => true, 'message' => 'Product disabled.']);
    }

    public function destroy($id)
    {
        $product = Product::with('seller')->findOrFail($id);
        $name    = $product->name;
        $pid     = $product->id;
        $seller  = $product->seller;
        $product->delete();
        if ($seller) {
            $seller->notify(new ProductActionNotification('deleted', $pid, $name, 'Admin', 0));
        }
        return response()->json(['success' => true, 'message' => 'Product deleted.']);
    }

    private function deriveStatus(Product $product): string
    {
        if (!$product->is_approved) return 'pending';
        if (!$product->is_active)   return 'disabled';
        return 'approved';
    }
}