<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Order;
use Illuminate\Http\Request;

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
                'complaints' => fn($q) => $q->select('id', 'order_id', 'reference', 'status', 'refund_amount', 'refund_method', 'refund_reference', 'refunded_at', 'created_at'),
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
                'complaints' => fn($q) => $q->select('id', 'order_id', 'reference', 'status', 'refund_amount', 'refund_method', 'refund_reference', 'refunded_at', 'created_at'),
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
            'refunded'         => (clone $base)->where('status', 'refunded')->count(),
            'partially_returned' => (clone $base)->where('return_status', 'partial')->whereNotIn('status', ['cancelled', 'refunded'])->count(),
        ]]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function transformOrder(Order $order): array
    {
        $sellerOrderMap = $order->sellerOrders->keyBy('id');

        // ── Enrich items ───────────────────────────────────────────────────
        $enrichedItems = $order->items->map(function ($item) use ($sellerOrderMap, $order) {
            // As bought: the checkout snapshot first, never another variant's image
            $item->setAttribute('resolved_image_url', $item->displayImageUrl());
            $item->setAttribute('variant_label', $item->displayVariantLabel());

            $so = $item->seller_order_id
                ? ($sellerOrderMap[$item->seller_order_id] ?? null)
                : null;

            $item->seller_order_id      = $so?->id;
            $item->seller_order_status  = $so?->status         ?? $order->status;
            $item->seller_order_payment = $so?->payment_status ?? $order->payment_status;

            // Returned units are taken off the line when the return is refunded:
            // ordered_quantity / returned_quantity / return_state tell the story.
            $item->setAttribute('is_returned', $item->return_state === 'returned');

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
                'display_status'  => $so->display_status,
                'return_status'   => $so->return_status,
                'payment_status'  => $so->payment_status,
                'subtotal'        => (float) $so->subtotal,
                'coupon_code'     => $so->coupon_code,
                'discount_amount' => round((float) ($so->discount_amount ?? 0), 3),
                'items'           => $groupItems,
                'tracking'        => $tracking,
            ];
        })->values();

        $arr                  = $order->toArray();
        $arr['items']         = $enrichedItems->values();
        $arr['seller_groups'] = $sellerGroups;
        // Returns on this order (tracking lives at /complaints?id=)
        $arr['returns'] = $order->relationLoaded('complaints')
            ? $order->complaints->sortByDesc('created_at')->values()->map(fn($c) => [
                'id'               => $c->id,
                'reference'        => $c->reference,
                'status'           => $c->status,
                'refund_amount'    => (float) $c->refund_amount,
                'refund_method'    => $c->refund_method,
                'refund_reference' => $c->status === 'refunded' ? $c->refund_reference : null,
                'refunded_at'      => $c->refunded_at,
                'created_at'       => $c->created_at,
            ])
            : [];
        unset($arr['complaints']);

        // Live breakdown from active seller_orders (orders.total_amount may be
        // stale if a partial return reduced a seller subtotal):
        //   total = subtotal − discount_amount + shipping_fee
        // A cancelled order owes 0: is_cancelled + original_amounts let the
        // storefront strike the checkout amounts through.
        $money = $order->moneySummary();
        $arr['subtotal']         = $money['subtotal'];
        $arr['discount_amount']  = $money['discount_amount'];
        $arr['coupon_codes']     = $money['coupon_codes'];
        $arr['shipping_fee']     = $money['shipping_fee'];
        $arr['total_amount']     = $money['total'];
        $arr['amount_due']       = $money['total'];
        $arr['is_cancelled']     = $money['is_cancelled'];
        $arr['original_amounts'] = $money['original'];

        return $arr;
    }
}
