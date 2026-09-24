<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductAttributeValue;
use App\Models\Attribute;
use App\Models\ProductModerationLog;
use App\Models\Review;
use App\Models\SellerSubscription;
use App\Http\Requests\Admin\RejectProductRequest;
use App\Http\Requests\Admin\RequestProductChangesRequest;
use App\Http\Resources\Admin\ProductReviewResource;
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
    $status = $request->query('status', 'pending');

    if ($status === 'deleted_by_seller') {
        $query = Product::onlyTrashed()
            ->where('deleted_by_seller', true)
            ->with(['seller:id,name,email', 'category:id,name', 'primaryImage']);

        if ($search = $request->query('search'))
            $query->where('name', 'like', '%' . $search . '%');
        if ($sellerId = $request->query('seller_id'))
            $query->where('seller_id', $sellerId);

        $products = $query->orderByDesc('deleted_at')
            ->paginate((int) $request->query('per_page', 15));

        $products->getCollection()->transform(function ($product) {
            $product->status = 'deleted_by_seller';
            $product->primary_image_url = $product->primaryImage
                ? Storage::disk('public')->url($product->primaryImage->image_path)
                : null;
            return $product;
        });

        return response()->json(['success' => true, 'data' => $products]);
    }

    // Normal statuses — SoftDeletes automatically excludes deleted_at IS NOT NULL
    $query = Product::with([
        'seller:id,name,email',
        'category:id,name',
        'primaryImage',
    ]);

    if ($status === 'pending') {
        $this->scopePendingQueue($query);
    } elseif ($status === 'changes_requested') {
        $query->where('is_approved', false)
              ->whereNull('rejection_reason')
              ->whereNotNull('changes_requested_at');
    } elseif ($status === 'rejected') {
        $query->where('is_approved', false)
              ->whereNotNull('rejection_reason');
    } elseif ($status === 'approved') {
        $query->where('is_approved', true)->where('is_active', true);
    } elseif ($status === 'disabled') {
        $query->where('is_approved', true)->where('is_active', false);
    }
    // 'all' → SoftDeletes excludes soft-deleted automatically

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

        $product->variant_rows = $product->variants->map(fn($v) => [
            'id'             => $v->id,
            'option_ids'     => $v->attributeOptions->pluck('id')->toArray(),
            'stock'          => $v->stock,
            'price_override' => $v->price_override !== null ? (string) $v->price_override : '',
            'sku'            => $v->sku ?? '',
            'is_active'      => $v->is_active,
            'image_urls'     => $v->images->map(fn($i) => Storage::disk('public')->url($i->image_path))->toArray(),
        ]);

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
            'season'            => 'sometimes|nullable|string|max:30',
            'delivery_fee'      => 'sometimes|nullable|numeric|min:0',
        ]);

        $fieldsToUpdate = [];
        foreach (['name', 'description', 'short_description', 'price', 'stock',
                  'category_id', 'subcategory_id', 'is_approved', 'featured', 'season','delivery_fee'] as $field) {
            if ($request->has($field)) {
                $fieldsToUpdate[$field] = $request->input($field);
            }
        }

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

        if ($request->has('is_active') && !$request->boolean('is_active')) {
            $fieldsToUpdate['is_active'] = false;
        }

        if (isset($fieldsToUpdate['category_id']) &&
            $fieldsToUpdate['category_id'] != $product->category_id &&
            !$request->has('subcategory_id')) {
            $fieldsToUpdate['subcategory_id'] = null;
        }

        if (!empty($fieldsToUpdate)) {
            $product->update($fieldsToUpdate);
        }

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

            $product->fresh()->syncActiveStatusFromVariants();
        }

        if ($product->seller) {
            try {
                $product->seller->notify(new ProductActionNotification(
                    'updated', $product->id, $product->name, 'Admin', 0
                ));
            } catch (\Throwable $e) {
                Log::warning('[AdminProduct::update] Notification failed: ' . $e->getMessage());
            }
        }

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

    /**
     * GET /api/admin/products/{id}/review
     * Complete moderation payload (see ProductReviewResource). Kept separate from
     * show(), whose shape AdminEditProductModal depends on.
     */
    public function review(Request $request, $id)
    {
        $product = Product::withTrashed()->with([
            'seller:id,name,email,is_active,is_approved,created_at',
            'seller.sellerApplication',
            'category:id,name,slug',
            'subcategory:id,name,slug,category_id',
            'images.colorOption:id,value,color_hex',
            'attributeValues.attribute.options',
            'variants' => fn($q) => $q->with([
                'attributeOptions.attribute:id,slug,name,type',
                'images',
            ]),
            'promotions',
            'coupons',
            'moderationLogs.admin:id,name',
        ])->findOrFail($id);

        $sellerId     = $product->seller_id;
        $subscription = $sellerId ? SellerSubscription::where('user_id', $sellerId)->first() : null;
        $plan         = optional($subscription)->current_plan
            ?? optional(optional($product->seller)->sellerApplication)->plan
            ?? 'free';

        $resource = new ProductReviewResource($product, [
            'seller_plan'    => \App\Models\SubscriptionPlan::forSlug($plan)->slug,
            'subscription'   => $subscription,
            'seller_stats'   => $sellerId ? $this->sellerStats($sellerId) : [],
            'performance'    => $this->performance($product),
            'duplicate_skus' => $this->duplicateSkus($product),
            'queue'          => $this->queue($product),
        ]);

        return response()->json(['success' => true, 'data' => $resource->resolve($request)]);
    }

    public function approve(Request $request, $id)
    {
        $product = Product::with('seller')->findOrFail($id);
        $from    = $product->moderationStatus();

        // Clear rejection / change-request state so the product goes back to a clean state
        $product->update(['is_approved' => true, 'rejection_reason' => null, 'changes_requested_at' => null]);
        $product = $product->fresh('seller');
        $product->syncActiveStatusFromVariants();

        ProductModerationLog::record($product, 'approved', [
            'admin_id'    => $request->user()->id,
            'from_status' => $from,
            'to_status'   => $product->moderationStatus(),
        ]);

        if ($product->seller) {
            $product->seller->notify(new ProductReviewedNotification('approved', $product->id, $product->name));
        }
        return response()->json(['success' => true, 'message' => 'Product approved.']);
    }

    /**
     * PATCH /api/admin/products/{id}/reject
     * Body: { reasons: string[] (codes), note?: string } — legacy { reason } still accepted.
     */
    public function reject(RejectProductRequest $request, $id)
    {
        $product = Product::with('seller')->findOrFail($id);
        $from    = $product->moderationStatus();
        $reasons = $request->input('reasons', []);
        $note    = trim((string) $request->input('note')) ?: null;
        $labels  = ProductModerationLog::labelsFor(array_values(array_diff($reasons, ['other'])));

        // rejection_reason doubles as the pending/rejected discriminator — never empty here
        $summary = implode('; ', $labels);
        if ($note) $summary = $summary ? $summary . ' — ' . $note : $note;

        $product->update([
            'is_approved'          => false,
            'is_active'            => false,
            'rejection_reason'     => $summary ?: 'Other',
            'changes_requested_at' => null,
        ]);

        ProductModerationLog::record($product, 'rejected', [
            'admin_id'    => $request->user()->id,
            'from_status' => $from,
            'to_status'   => 'rejected',
            'reasons'     => $reasons,
            'note'        => $note,
        ]);

        if ($product->seller) {
            $product->seller->notify(
                new ProductReviewedNotification('rejected', $product->id, $product->name, $note, $labels)
            );
        }
        return response()->json(['success' => true, 'message' => 'Product rejected.']);
    }

    /**
     * PATCH /api/admin/products/{id}/request-changes
     * Sends a product awaiting review back to the seller with notes. It returns
     * to `pending` automatically when the seller edits it.
     */
    public function requestChanges(RequestProductChangesRequest $request, $id)
    {
        $product = Product::with('seller')->findOrFail($id);
        $from    = $product->moderationStatus();

        if (!in_array($from, ['pending', 'changes_requested'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Changes can only be requested on products awaiting review.',
            ], 422);
        }

        $reasons = $request->input('reasons', []) ?: [];
        $note    = trim((string) $request->input('note'));
        $labels  = ProductModerationLog::labelsFor(array_values(array_diff($reasons, ['other'])));

        $product->update([
            'is_approved'          => false,
            'rejection_reason'     => null,
            'changes_requested_at' => now(),
        ]);

        ProductModerationLog::record($product, 'changes_requested', [
            'admin_id'    => $request->user()->id,
            'from_status' => $from,
            'to_status'   => 'changes_requested',
            'reasons'     => $reasons,
            'note'        => $note,
        ]);

        if ($product->seller) {
            $product->seller->notify(
                new ProductReviewedNotification('changes_requested', $product->id, $product->name, $note, $labels)
            );
        }
        return response()->json(['success' => true, 'message' => 'Changes requested from the seller.']);
    }

    /**
     * PATCH /api/admin/products/{id}/featured   Body: { featured: bool }
     */
    public function toggleFeatured(Request $request, $id)
    {
        $request->validate(['featured' => 'required|boolean']);
        $product  = Product::findOrFail($id);
        $featured = $request->boolean('featured');

        if ($product->featured !== $featured) {
            $product->update(['featured' => $featured]);
            $status = $product->moderationStatus();
            ProductModerationLog::record($product, $featured ? 'featured' : 'unfeatured', [
                'admin_id'    => $request->user()->id,
                'from_status' => $status,
                'to_status'   => $status,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $featured ? 'Product featured.' : 'Product removed from featured.',
            'data'    => ['featured' => $featured],
        ]);
    }

    // ── Review payload helpers ─────────────────────────────────────────────────

    private function sellerStats(int $sellerId): array
    {
        $counts = Product::where('seller_id', $sellerId)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(is_approved = 1) AS approved')
            ->selectRaw('SUM(is_approved = 0 AND rejection_reason IS NOT NULL) AS rejected')
            ->selectRaw('SUM(is_approved = 0 AND rejection_reason IS NULL) AS pending')
            ->first();

        $rating = Review::where('seller_id', $sellerId)->where('status', 'approved')
            ->selectRaw('AVG(rating) AS avg_rating, COUNT(*) AS review_count')
            ->first();

        return [
            'total_products'    => (int) ($counts->total ?? 0),
            'approved_products' => (int) ($counts->approved ?? 0),
            'rejected_products' => (int) ($counts->rejected ?? 0),
            'pending_products'  => (int) ($counts->pending ?? 0),
            'rating_avg'        => $rating && $rating->avg_rating !== null ? round((float) $rating->avg_rating, 2) : null,
            'review_count'      => (int) ($rating->review_count ?? 0),
        ];
    }

    private function performance(Product $product): array
    {
        $sales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $product->id)
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->selectRaw('COUNT(DISTINCT order_items.order_id) AS orders_count')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) AS units_sold')
            ->selectRaw('COALESCE(SUM(order_items.total), 0) AS revenue')
            ->first();

        $reviews = Review::where('product_id', $product->id)->where('status', 'approved')
            ->selectRaw('AVG(rating) AS avg_rating, COUNT(*) AS review_count')
            ->first();

        return [
            'views'        => (int) $product->views,
            'orders_count' => (int) ($sales->orders_count ?? 0),
            'units_sold'   => (int) ($sales->units_sold ?? 0),
            'revenue'      => round((float) ($sales->revenue ?? 0), 3),
            'rating_avg'   => $reviews && $reviews->avg_rating !== null ? round((float) $reviews->avg_rating, 2) : null,
            'review_count' => (int) ($reviews->review_count ?? 0),
        ];
    }

    /** SKUs of this product that repeat within it or appear on another product / variant. */
    private function duplicateSkus(Product $product): array
    {
        $skus = collect([$product->sku])
            ->merge($product->variants->pluck('sku'))
            ->map(fn($s) => is_string($s) ? trim($s) : $s)
            ->filter();

        if ($skus->isEmpty()) return [];

        $unique = $skus->unique()->values();

        return $skus->duplicates()
            ->merge(Product::withTrashed()->where('id', '!=', $product->id)->whereIn('sku', $unique)->pluck('sku'))
            ->merge(ProductVariant::where('product_id', '!=', $product->id)->whereIn('sku', $unique)->pluck('sku'))
            ->unique()->values()->all();
    }

    /** Position in the pending queue (same order as the list: newest first). */
    private function queue(Product $product): array
    {
        $pending = fn() => $this->scopePendingQueue(Product::query())->where('id', '!=', $product->id);

        $next = $pending()
            ->where(fn($q) => $q->where('created_at', '<', $product->created_at)
                ->orWhere(fn($q2) => $q2->where('created_at', $product->created_at)->where('id', '<', $product->id)))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->value('id')
            ?? $pending()->orderByDesc('created_at')->orderByDesc('id')->value('id');

        return [
            'next_pending_id' => $next,
            'pending_count'   => $this->scopePendingQueue(Product::query())->count(),
        ];
    }

    public function disable(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        $from    = $product->moderationStatus();
        $product->update(['is_active' => false]);
        ProductModerationLog::record($product, 'disabled', [
            'admin_id'    => $request->user()->id,
            'from_status' => $from,
            'to_status'   => $product->moderationStatus(),
        ]);
        return response()->json(['success' => true, 'message' => 'Product disabled.']);
    }

    public function destroy($id)
{
    $product = Product::with('seller')->findOrFail($id);
    $name    = $product->name;
    $pid     = $product->id;
    $seller  = $product->seller;

    // Manually clean up
    foreach ($product->images as $img) {
        Storage::disk('public')->delete($img->image_path);
    }
    $product->images()->delete();
    $product->attributeValues()->delete();

    \App\Models\OrderItem::whereIn(
        'variant_id',
        $product->variants()->pluck('id')
    )->update(['variant_id' => null]);

    $product->variants()->delete();
    $product->forceDelete(); // use forceDelete so boot hook doesn't interfere

    if ($seller) {
        $seller->notify(new ProductActionNotification('deleted', $pid, $name, 'Admin', 0));
    }

    return response()->json(['success' => true, 'message' => 'Product deleted.']);
}
    /**
 * POST /api/admin/products/{id}/restore
 * Restores a seller-deleted product back to pending review.
 */
public function restore(Request $request, $id)
{
    $product = Product::onlyTrashed()->findOrFail($id);
    $product->restore();
    $product->update([
        'is_approved'          => false,
        'is_active'            => false,
        'deleted_by_seller'    => false, // ← clear flag
        'rejection_reason'     => null,
        'changes_requested_at' => null,
    ]);
    ProductModerationLog::record($product, 'restored', [
        'admin_id'    => $request->user()->id,
        'from_status' => 'deleted_by_seller',
        'to_status'   => 'pending',
    ]);
    return response()->json(['success' => true, 'message' => 'Product restored to pending review.']);
}

/**
 * DELETE /api/admin/products/{id}/force
 * Permanently deletes a seller-deleted product (admin decision).
 */
public function forceDestroy($id)
{
    $product = Product::onlyTrashed()->findOrFail($id);

    // Manually clean up images since boot hook no longer does it
    foreach ($product->images as $img) {
        Storage::disk('public')->delete($img->image_path);
    }
    $product->images()->delete();
    $product->attributeValues()->delete();

    \App\Models\OrderItem::whereIn(
        'variant_id',
        $product->variants()->pluck('id')
    )->update(['variant_id' => null]);

    $product->variants()->delete();
    $product->forceDelete();

    return response()->json([
        'success' => true,
        'message' => 'Product permanently deleted.',
    ]);
}

    /** Display status — single source of truth lives on the model. */
    private function deriveStatus(Product $product): string
    {
        return $product->moderationStatus();
    }

    /** Products waiting for a first (or renewed) review. */
    private function scopePendingQueue($query)
    {
        return $query->where('is_approved', false)
            ->whereNull('rejection_reason')
            ->whereNull('changes_requested_at')
            ->where(fn($q) => $q->where('deleted_by_seller', false)
                                ->orWhereNull('deleted_by_seller'));
    }
}