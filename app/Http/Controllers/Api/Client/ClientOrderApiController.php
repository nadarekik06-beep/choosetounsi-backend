<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * FILE: app/Http/Controllers/Api/Client/ClientOrderApiController.php  ← REPLACE
 *
 * CHANGE from previous version:
 *   transformOrder() now includes delivery tracking timestamps in seller_groups[].
 *   Each seller group gains a 'tracking' object:
 *
 *   {
 *     picked_up_at:  string|null   ← when delivery guy picked up from seller
 *     delivered_at:  string|null   ← when delivery guy confirmed delivery
 *     assigned_at:   string|null   ← when delivery was assigned
 *     delivery_guy:  string|null   ← delivery person's name
 *   }
 *
 *   This powers the OrderTracker stepper on the client storefront.
 *   All existing logic is preserved exactly — this is purely additive.
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
                'sellerOrders.deliveryAssignment.deliveryGuy:id,name', // ← NEW
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
                'items' => fn($q) => $q->with([
                    'product' => fn($pq) => $pq->withTrashed()->with(['images', 'primaryImage']),
                ]),
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
                'sellerOrders.deliveryAssignment.deliveryGuy:id,name', // ← NEW
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
                'items' => fn($q) => $q->with([
                    'product' => fn($pq) => $pq->withTrashed()->with(['images', 'primaryImage']),
                ]),
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
            'total'            => (clone $base)->count(),
            'pending'          => (clone $base)->where('status', 'pending')->count(),
            'completed'        => (clone $base)->where('status', 'completed')->count(),
            'delivered'        => (clone $base)->where('status', 'delivered')->count(),
            'out_for_delivery' => (clone $base)->where('status', 'out_for_delivery')->count(),
            'cancelled'        => (clone $base)->where('status', 'cancelled')->count(),
        ]]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Transform an Order into the client-facing shape.
     *
     * CHANGE: seller_groups now includes a 'tracking' key per group:
     *   {
     *     assigned_at:   ISO timestamp when delivery was assigned
     *     picked_up_at:  ISO timestamp when agent picked up from seller
     *     delivered_at:  ISO timestamp when agent confirmed delivery
     *     delivery_guy:  name of the delivery person (or null)
     *   }
     *
     * This is purely additive — no existing fields are changed.
     */
    private function transformOrder(Order $order): array
    {
        $sellerOrderMap = $order->sellerOrders->keyBy('id');

        $enrichedItems = $order->items->map(function ($item) use ($sellerOrderMap, $order) {
            $item->setAttribute(
                'resolved_image_url',
                $this->resolveImageUrl($item->product, $item->variant)
            );

            $so = $item->seller_order_id
                ? ($sellerOrderMap[$item->seller_order_id] ?? null)
                : null;

            $item->seller_order_id      = $so?->id;
            $item->seller_order_status  = $so?->status          ?? $order->status;
            $item->seller_order_payment = $so?->payment_status  ?? $order->payment_status;

            return $item;
        });

        // Build seller_groups with tracking timestamps ─────────────────────
        $sellerGroups = $order->sellerOrders->map(function ($so) use ($enrichedItems) {
            $groupItems = $enrichedItems
                ->filter(fn($i) => $i->seller_order_id === $so->id)
                ->values();

            // ── Delivery tracking timestamps (NEW) ─────────────────────────
            // Sourced from delivery_assignments via eager-loaded relation.
            // All fields are null if no delivery assignment exists yet.
            $assignment = $so->relationLoaded('deliveryAssignment')
                ? $so->deliveryAssignment
                : null;

            $tracking = [
                'assigned_at'  => $assignment?->assigned_at?->toISOString(),
                'picked_up_at' => $assignment?->picked_up_at?->toISOString(),
                'delivered_at' => $assignment?->delivered_at?->toISOString(),
                'delivery_guy' => $assignment?->relationLoaded('deliveryGuy')
                    ? $assignment->deliveryGuy?->name
                    : null,
            ];

            return [
                'seller_order_id' => $so->id,
                'status'          => $so->status,
                'payment_status'  => $so->payment_status,
                'subtotal'        => (float) $so->subtotal,
                'items'           => $groupItems,
                'tracking'        => $tracking, // ← NEW
            ];
        })->values();

        $arr                  = $order->toArray();
        $arr['items']         = $enrichedItems->values();
        $arr['seller_groups'] = $sellerGroups;

        return $arr;
    }

    /**
     * Resolve the best image URL for an order item.
     * Unchanged from previous version.
     */
    private function resolveImageUrl(?Product $product, ?ProductVariant $variant): ?string
    {
        if (!$product) return null;

        if ($variant) {
            if ($variant->relationLoaded('images') && $variant->images->isNotEmpty()) {
                $img = $variant->images->firstWhere('is_primary', true)
                    ?? $variant->images->sortBy('order')->first();
                if ($img) return Storage::url($img->image_path);
            }

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

            if ($colorOptId) {
                $img = \App\Models\ProductImage::where('product_id', $product->id)
                    ->where('color_option_id', $colorOptId)
                    ->orderBy('order')
                    ->first();
                if ($img) return Storage::url($img->image_path);
            }
        }

        if ($product->relationLoaded('images')) {
            $img = $product->images->firstWhere('is_primary', true)
                ?? $product->images->sortBy('order')->first();
            if ($img) return Storage::url($img->image_path);
        }

        $img = \App\Models\ProductImage::where('product_id', $product->id)
            ->orderByDesc('is_primary')
            ->orderBy('order')
            ->first();

        return $img ? Storage::url($img->image_path) : null;
    }
}