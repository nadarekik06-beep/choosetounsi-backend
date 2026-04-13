<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * ClientOrderApiController
 *
 * ── KEY CHANGE ──
 * Order items are now grouped by seller_order_id so the client-facing
 * "My Orders" page can display per-seller statuses.
 *
 * Each item now carries:
 *   - resolved_image_url   (variant-aware, set via setAttribute so it wins
 *                           over the stale image_url snapshot in toArray())
 *   - seller_order_status  (the status of the seller's sub-order for this item)
 *   - seller_order_payment (the payment status of the seller's sub-order)
 *   - seller_order_id      (for reference)
 *
 * The order's top-level `status` remains the admin/platform view.
 * The per-item `seller_order_status` is what's shown to clients per item group.
 *
 * ── IMAGE FIX ──
 * Previously, $item->resolved_image_url = ... set a dynamic property that was
 * overwritten during toArray() by the OrderItem accessor reading the stale
 * image_url column (which stored the product's default image at checkout time).
 *
 * Fix: use $item->setAttribute('resolved_image_url', ...) so the value lands
 * in $this->attributes[], where the accessor's array_key_exists() check picks
 * it up and returns it immediately — bypassing the stale snapshot entirely.
 */
class ClientOrderApiController extends Controller
{
    /**
     * GET /api/client/orders
     */
    public function index(Request $request)
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->with([
                'sellerOrders',
                'items.product.images',
                'items.product.primaryImage',
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        $orders->getCollection()->transform(function ($order) {
            return $this->transformOrder($order);
        });

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /**
     * GET /api/client/orders/{id}
     */
    public function show(Request $request, $id)
    {
        $order = Order::where('user_id', $request->user()->id)
            ->with([
                'sellerOrders',
                'items.product.images',
                'items.product.primaryImage',
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
            ])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->transformOrder($order)]);
    }

    /**
     * GET /api/client/statistics
     */
    public function statistics(Request $request)
    {
        $userId = $request->user()->id;
        $base   = Order::where('user_id', $userId);

        return response()->json(['success' => true, 'data' => [
            'total'     => (clone $base)->count(),
            'pending'   => (clone $base)->where('status', 'pending')->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'delivered' => (clone $base)->where('status', 'delivered')->count(),
            'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
        ]]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Transform an Order into the client-facing shape.
     *
     * Items are enriched with:
     *   resolved_image_url    — variant-aware image (set via setAttribute so it
     *                           survives toArray() without being overwritten by
     *                           the accessor reading the stale image_url column)
     *   seller_order_id       — which sub-order this item belongs to
     *   seller_order_status   — the seller's own status for this item
     *   seller_order_payment  — the seller's own payment status
     *
     * Additionally, `seller_groups` is added to the order: an array of
     * { seller_order_id, status, payment_status, subtotal, items[] }
     * This allows the frontend to render per-seller sections cleanly.
     */
    private function transformOrder(Order $order): array
    {
        // Build a lookup map: seller_order_id → SellerOrder
        $sellerOrderMap = $order->sellerOrders->keyBy('id');

        // Enrich each item with image URL + seller sub-order info
        $enrichedItems = $order->items->map(function ($item) use ($sellerOrderMap, $order) {

            // ── IMAGE FIX ──────────────────────────────────────────────────
            // Use setAttribute() instead of a dynamic property assignment.
            // This puts the resolved URL into $this->attributes[] so that
            // when toArray() calls the getResolvedImageUrlAttribute() accessor,
            // the array_key_exists() guard at the top of the accessor returns
            // our correct variant-aware URL immediately — instead of falling
            // through to read image_url (the stale product-level snapshot).
            $item->setAttribute(
                'resolved_image_url',
                $this->resolveImageUrl($item->product, $item->variant)
            );

            // Attach the seller's status to this item
            $so = $item->seller_order_id
                ? ($sellerOrderMap[$item->seller_order_id] ?? null)
                : null;

            $item->seller_order_id      = $so?->id;
            $item->seller_order_status  = $so?->status          ?? $order->status;
            $item->seller_order_payment = $so?->payment_status  ?? $order->payment_status;

            return $item;
        });

        // Build seller_groups: one group per seller sub-order
        $sellerGroups = $order->sellerOrders->map(function ($so) use ($enrichedItems) {
            $groupItems = $enrichedItems
                ->filter(fn($i) => $i->seller_order_id === $so->id)
                ->values();

            return [
                'seller_order_id' => $so->id,
                'status'          => $so->status,
                'payment_status'  => $so->payment_status,
                'subtotal'        => (float) $so->subtotal,
                'items'           => $groupItems,
            ];
        })->values();

        $arr                  = $order->toArray();
        $arr['items']         = $enrichedItems->values();
        $arr['seller_groups'] = $sellerGroups;

        return $arr;
    }

    /**
     * Resolve the best image URL for an order item.
     *
     * Priority:
     *   1. Variant's own images (rows in product_images with variant_id set)
     *   2. Product images grouped by the variant's color_option_id
     *      (rows in product_images with color_option_id matching the variant's
     *       primary color attribute option)
     *   3. Product primary image fallback (is_primary = true, or first by order)
     *   4. null
     */
    private function resolveImageUrl(?Product $product, ?ProductVariant $variant): ?string
    {
        if (!$product) return null;

        if ($variant) {
            // 1. Variant's own images (variant_id foreign key on product_images)
            if ($variant->relationLoaded('images') && $variant->images->isNotEmpty()) {
                $img = $variant->images->firstWhere('is_primary', true)
                    ?? $variant->images->sortBy('order')->first();
                if ($img) return Storage::url($img->image_path);
            }

            // 2. Color-grouped product images (color_option_id on product_images)
            $colorOptId = null;
            if ($variant->relationLoaded('attributeOptions')) {
                $colorOpt = $variant->attributeOptions->first(
                    fn($o) => $o->relationLoaded('attribute') && $o->attribute->slug === 'color'
                );
                $colorOptId = $colorOpt?->id;
            }

            if ($colorOptId && $product->relationLoaded('images')) {
                $img = $product->images
                    ->where('color_option_id', $colorOptId)
                    ->sortBy('order')
                    ->first();
                if ($img) return Storage::url($img->image_path);
            }

            // 2b. Direct DB fallback — in case the eager-loaded collection
            //     didn't yield a color image (e.g. images loaded without
            //     color_option_id filter, or relation partially hydrated)
            if ($colorOptId) {
                $img = \App\Models\ProductImage::where('product_id', $product->id)
                    ->where('color_option_id', $colorOptId)
                    ->orderBy('order')
                    ->first();
                if ($img) return Storage::url($img->image_path);
            }
        }

        // 3. Product primary image (from eager-loaded collection)
        if ($product->relationLoaded('images')) {
            $img = $product->images->firstWhere('is_primary', true)
                ?? $product->images->sortBy('order')->first();
            if ($img) return Storage::url($img->image_path);
        }

        // 3b. Direct DB fallback for primary image
        $img = \App\Models\ProductImage::where('product_id', $product->id)
            ->orderByDesc('is_primary')
            ->orderBy('order')
            ->first();

        return $img ? Storage::url($img->image_path) : null;
    }
}