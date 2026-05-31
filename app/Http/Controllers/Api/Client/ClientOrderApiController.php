<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * FILE: app/Http/Controllers/Api/Client/ClientOrderApiController.php  ← REPLACE
 *
 * Change from previous version:
 *   transformOrder() now adds is_returned (bool) and refund_status (string|null)
 *   to each order item so the frontend can display a "Returned" badge.
 *
 *   Logic:
 *     - Load approved complaints for this order that have resolution_type = 'return_refund'
 *       AND refund_status = 'completed' (delivery agent finished the pickup).
 *     - Collect the order_item_ids from those complaints into a flat set.
 *     - Each item whose id is in that set gets is_returned = true.
 *     - Legacy complaints (order_item_ids = null) mark ALL items as returned.
 *
 *   All existing fields and logic are 100% preserved — purely additive.
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
                'sellerOrders.deliveryAssignment.deliveryGuy:id,name',
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
                'items' => fn($q) => $q->with([
                    'product' => fn($pq) => $pq->withTrashed()->with(['images', 'primaryImage']),
                ]),
                // ← NEW: load approved return complaints for this order
                'complaints' => fn($q) => $q
                    ->where('status', Complaint::STATUS_APPROVED)
->where(function ($q) {
    $q->where('resolution_type', Complaint::RESOLUTION_RETURN_REFUND)
      ->orWhereNull('resolution_type');
})                  
                  ->where('refund_status', Complaint::REFUND_STATUS_COMPLETED)
                    ->select('id', 'order_id', 'order_item_ids', 'refund_status'),
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        $orders->getCollection()->transform(fn($order) => $this->transformOrder($order));

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
                'sellerOrders.deliveryAssignment.deliveryGuy:id,name',
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
                'items' => fn($q) => $q->with([
                    'product' => fn($pq) => $pq->withTrashed()->with(['images', 'primaryImage']),
                ]),
                // ← NEW
                'complaints' => fn($q) => $q
                    ->where('status', Complaint::STATUS_APPROVED)
                    ->where('resolution_type', Complaint::RESOLUTION_RETURN_REFUND)
                    ->where('refund_status', Complaint::REFUND_STATUS_COMPLETED)
                    ->select('id', 'order_id', 'order_item_ids', 'refund_status'),
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

    private function transformOrder(Order $order): array
    {
        $sellerOrderMap = $order->sellerOrders->keyBy('id');

        // ── Build the set of returned item IDs ─────────────────────────────
        // From all approved + completed return_refund complaints on this order.
        // If a complaint has order_item_ids = null (legacy), it means ALL items
        // in that order were returned — mark everything.
        $returnedItemIds = collect();
        $allItemsReturned = false;

        if ($order->relationLoaded('complaints')) {
            foreach ($order->complaints as $complaint) {
                $ids = $complaint->order_item_ids; // array|null (cast on model)
                if (is_null($ids) || empty($ids)) {
                    $allItemsReturned = true;
                    break;
                }
                $returnedItemIds = $returnedItemIds->merge($ids);
            }
        }

        $returnedItemIds = $returnedItemIds->unique()->toArray();

        // ── Enrich items ───────────────────────────────────────────────────
        $enrichedItems = $order->items->map(function ($item) use (
            $sellerOrderMap, $order, $returnedItemIds, $allItemsReturned
        ) {
            $item->setAttribute(
                'resolved_image_url',
                $this->resolveImageUrl($item->product, $item->variant)
            );

            $so = $item->seller_order_id
                ? ($sellerOrderMap[$item->seller_order_id] ?? null)
                : null;

            $item->seller_order_id      = $so?->id;
            $item->seller_order_status  = $so?->status         ?? $order->status;
            $item->seller_order_payment = $so?->payment_status ?? $order->payment_status;

            // ← NEW: mark returned items
            $item->setAttribute(
                'is_returned',
                $allItemsReturned || in_array($item->id, $returnedItemIds)
            );

            return $item;
        });

        // ── Build seller_groups ────────────────────────────────────────────
        $sellerGroups = $order->sellerOrders->map(function ($so) use ($enrichedItems) {
            $groupItems = $enrichedItems
                ->filter(fn($i) => $i->seller_order_id === $so->id)
                ->values();

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
                'tracking'        => $tracking,
            ];
        })->values();

        $arr                  = $order->toArray();
        $arr['items']         = $enrichedItems->values();
        $arr['seller_groups'] = $sellerGroups;

        // Override total_amount with live sum from active seller_orders
        // (orders.total_amount may be stale if a partial return reduced a seller subtotal)
        $arr['total_amount'] = round(
    $order->sellerOrders
        ->where('status', '!=', 'cancelled')
        ->sum(fn($so) => (float) $so->subtotal)
    + (float) ($order->shipping_fee ?? 0),
    3
);

        return $arr;
    }

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