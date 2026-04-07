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
 *   - resolved_image_url  (unchanged, variant-aware)
 *   - seller_order_status  (the status of the seller's sub-order for this item)
 *   - seller_order_payment (the payment status of the seller's sub-order)
 *   - seller_order_id      (for reference)
 *
 * The order's top-level `status` remains the admin/platform view.
 * The per-item `seller_order_status` is what's shown to clients per item group.
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
                'sellerOrders',                           // ← load all sub-orders
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
     *   resolved_image_url    — variant-aware image
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
            $item->resolved_image_url = $this->resolveImageUrl(
                $item->product,
                $item->variant
            );

            // Attach the seller's status to this item
            $so = $item->seller_order_id
                ? ($sellerOrderMap[$item->seller_order_id] ?? null)
                : null;

            $item->seller_order_id      = $so?->id;
            $item->seller_order_status  = $so?->status  ?? $order->status;
            $item->seller_order_payment = $so?->payment_status ?? $order->payment_status;

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
     *   1. Variant's own images
     *   2. Product images grouped by the variant's color_option_id
     *   3. Product primary image fallback
     *   4. null
     */
    private function resolveImageUrl(?Product $product, ?ProductVariant $variant): ?string
    {
        if (!$product) return null;

        if ($variant) {
            // 1. Variant's own images
            if ($variant->relationLoaded('images') && $variant->images->isNotEmpty()) {
                $img = $variant->images->firstWhere('is_primary', true)
                    ?? $variant->images->sortBy('order')->first();
                if ($img) return Storage::url($img->image_path);
            }

            // 2. Color-grouped product images
            $colorOptId = null;
            if ($variant->relationLoaded('attributeOptions')) {
                $colorOpt   = $variant->attributeOptions->first(
                    fn($o) => $o->attribute->slug === 'color'
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
        }

        // 3. Product primary image fallback
        if ($product->relationLoaded('images')) {
            $img = $product->images->firstWhere('is_primary', true)
                ?? $product->images->sortBy('order')->first();
            if ($img) return Storage::url($img->image_path);
        }

        return null;
    }
}