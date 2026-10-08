<?php

namespace App\Http\Controllers\Api\Seller;

use App\Exceptions\InsufficientStock;
use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Complaint;


/**
 * SellerOrderController
 *
 * ── KEY ARCHITECTURAL CHANGE ──
 * All operations now target the `seller_orders` table instead of `orders`.
 *
 * ── COMMISSION UPDATE ──
 * show() now returns per-item commission snapshot fields
 * (commission_percentage, commission_amount, seller_amount, plan_used, has_commission)
 * plus an aggregated `commission` block at the response root.
 * Legacy orders (commission_amount = 0) return has_commission = false
 * and all commission fields as null — UI hides gracefully.
 *
 * ── VARIANT FIX ──
 * show() includes full variant details (label, attributes, image).
 *
 * ── PRIVACY FIX ──
 * Customer email removed from all seller-facing responses.
 */
class SellerOrderController extends Controller
{
    /**
     * Base query: only this seller's sub-orders.
     */
    private function sellerOrderQuery(int $sellerId)
{
    return SellerOrder::where('seller_id', $sellerId)
        ->with([
            'order.user:id,name',
            'items.variant.attributeOptions.attribute',
            'items.variant.images',
            'items' => fn($q) => $q->with([
                'product' => fn($pq) => $pq->withTrashed()->with(['images']),
            ]),
        ]);
}

    /* ── GET /api/seller/orders/stats ── */
    public function stats(Request $request)
{
    $sellerId = auth()->id();
    $base     = SellerOrder::where('seller_id', $sellerId);

    return response()->json(['success' => true, 'data' => [
        'total'            => (clone $base)->count(),
        'pending'          => (clone $base)->where('status', 'pending')->count(),
        'confirmed'        => (clone $base)->where('status', 'confirmed')->count(), // ← was processing
        'completed'        => (clone $base)->where('status', 'completed')->count(),
        'delivered'        => (clone $base)->where('status', 'delivered')->count(),
        'cancelled'        => (clone $base)->where('status', 'cancelled')->count(),
        'out_for_delivery' => (clone $base)->where('status', 'out_for_delivery')->count(),
        'refunded'         => (clone $base)->where('status', 'refunded')->count(),
        'partially_returned' => (clone $base)->where('return_status', 'partial')->whereNotIn('status', ['cancelled', 'refunded'])->count(),
        'revenue'          => (clone $base)
            ->whereIn('status', ['completed', 'delivered'])
            ->sum(DB::raw('subtotal - discount_amount')),
    ]]);
}

    /* ── GET /api/seller/orders ── */
    public function index(Request $request)
    {
        $sellerId = auth()->id();
        $query    = $this->sellerOrderQuery($sellerId);

        if ($request->filled('status')) {
            $request->status === 'partially_returned'
                ? $query->where('return_status', 'partial')->whereNotIn('status', ['cancelled', 'refunded'])
                : $query->where('status', $request->status);
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('order', function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) =>
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                  );
            });
        }

        $sellerOrders = $query->latest()->paginate((int) $request->query('per_page', 12));
        $sellerOrders->getCollection()->transform(fn($so) => $this->formatSellerOrder($so));

        return response()->json(['success' => true, 'data' => $sellerOrders]);
    }

    /* ── GET /api/seller/orders/{id} ── */
    public function show(Request $request, $id)
    {
        $sellerId    = auth()->id();
        $sellerOrder = $this->sellerOrderQuery($sellerId)->findOrFail($id);
        // ── Map items with full variant + commission details ───────────────
$mappedItems = $sellerOrder->items->map(function ($item) {
            $productName = $item->product_name
                ?? $item->product?->name
                ?? "Product #{$item->product_id}";

            // ── As bought: the order line's purchase snapshot ─────────────
            // (image, attributes, label — later product edits don't change it)
            $snapshot          = $item->purchaseSnapshot();
            $variantAttributes = $snapshot['variant_attributes'];
            $variantLabel      = $snapshot['variant_label'];
            $resolvedImage     = $snapshot['image_url'];

            // ── Commission snapshot fields ────────────────────────────────
            // NEVER recalculate — always read stored values.
            // commission_amount = 0 means legacy order → has_commission = false.
            $commissionAmount = (float) ($item->commission_amount ?? 0);
            $hasCommission    = $commissionAmount > 0;

            return [
                // ── Core fields ───────────────────────────────────────────
                'id'                    => $item->id,
                'product_id'            => $item->product_id,
                'product_name'          => $productName,
                'quantity'              => (int)   $item->quantity,          // kept (after refunded returns)
                'ordered_quantity'      => $item->ordered_quantity,
                'returned_quantity'     => (int) $item->returned_quantity,
                'returned_amount'       => round((float) $item->returned_amount, 3),
                'unit_price'            => (float) $item->unit_price,
                'total'                 => (float) $item->total,
                'discount_amount'       => round((float) $item->discount_amount, 3),
                'net_total'             => round((float) ($item->net_total ?? $item->total), 3),

                // ── Variant fields ────────────────────────────────────────
                'variant_id'            => $item->variant_id,
                'variant_label'         => $variantLabel,
                'variant_attributes'    => $variantAttributes,
                'variant_image_url'     => $resolvedImage,

                // ── Commission fields (null for legacy orders) ────────────
                'has_commission'        => $hasCommission,
                'commission_percentage' => $hasCommission ? (float) $item->commission_percentage : null,
                'commission_amount'     => $hasCommission ? round($commissionAmount, 3)            : null,
                'seller_amount'         => $hasCommission ? round((float) $item->seller_amount, 3) : null,
                'plan_used'             => $hasCommission ? $item->plan_used                       : null,
                'is_returned'           => $item->return_state === 'returned',
                'item_status'           => $item->return_state,   // returned | partially_returned | null
            ];
        });

        // ── Commission order-level totals ─────────────────────────────────
        // Aggregate only from items that have commission data.
        // For legacy orders: has_commission = false, amounts = null.
        // Refunded returns already took their units / commission off the lines
        $commissionItems = $sellerOrder->items->filter(fn($i) => (float) ($i->commission_amount ?? 0) > 0);
$hasAnyCommission = $commissionItems->isNotEmpty();

// seller_subtotal is already adjusted by MarkOrderRefunded (returned items subtracted)
$totalGross            = round((float) $sellerOrder->subtotal, 3);
$totalDiscount         = round((float) ($sellerOrder->discount_amount ?? 0), 3);
$totalNet              = round($totalGross - $totalDiscount, 3); // what the customer paid for this seller's items
$totalCommissionAmount = $hasAnyCommission
    ? round($commissionItems->sum(fn($i) => (float) $i->commission_amount), 3)
    : null;
$totalSellerNet        = $hasAnyCommission
    ? round($commissionItems->sum(fn($i) => (float) $i->seller_amount), 3)
    : null;

// Free-shipping orders: the agency cost the seller pays, off their earnings.
$shippingCharge  = round((float) ($sellerOrder->seller_shipping_charge ?? 0), 3);
$netAfterShipping = $totalSellerNet !== null ? round($totalSellerNet - $shippingCharge, 3) : null;

        $order    = $sellerOrder->order;
        $customer = $order->user ? ['name' => $order->user->name] : null;

        return response()->json([
            'success' => true,
            'data'    => [
                'order' => array_merge($order->toArray(), [
                    'status'          => $sellerOrder->status,
                    'display_status'  => $sellerOrder->display_status,
                    'return_status'   => $sellerOrder->return_status,
                    'payment_status'  => $sellerOrder->payment_status,
                    'payment_method'  => $order->payment_method,
                    'wilaya'          => $order->wilaya ?? $order->shipping_address ?? null,
                    'customer'        => $customer,
                    'seller_order_id' => $sellerOrder->id,
                ]),
                'items'           => $mappedItems->values(),
                'seller_subtotal' => $totalGross,
                'discount_amount' => $totalDiscount,
                'coupon_code'     => $sellerOrder->coupon_code,
                'coupon_type'     => $sellerOrder->coupon_type,
                'coupon_value'    => $sellerOrder->coupon_value !== null ? (float) $sellerOrder->coupon_value : null,
                'seller_total'    => $totalNet,

                // ── Commission summary block ───────────────────────────────
                // Frontend reads detail.commission.has_commission to decide
                // whether to render the CommissionSummaryCard.
                'commission' => [
                    'has_commission'          => $hasAnyCommission,
                    'total_gross'             => $totalGross,
                    'total_discount'          => $totalDiscount,
                    'total_net'               => $totalNet,   // commission base
                    'total_commission_amount' => $totalCommissionAmount,
                    'total_seller_net'        => $totalSellerNet,
                    'shipping_paid_by_seller' => $shippingCharge,     // 0 unless free shipping
                    'net_after_shipping'      => $netAfterShipping,   // sale − commission − shipping
                ],
            ],
        ]);
    }

public function updateStatus(Request $request, $id)
{
    $request->validate([
'status' => 'required|in:pending,confirmed,out_for_delivery,completed,delivered,cancelled',
    ]);

    $sellerId    = auth()->id();
    $sellerOrder = SellerOrder::where('seller_id', $sellerId)->findOrFail($id);

    $status = $request->status;

    // Stock follows the status (SellerOrderObserver): cancelled gives the lines
    // back once, re-opening a cancelled sub-order reserves them again.
    try {
        $sellerOrder = DB::transaction(function () use ($sellerOrder, $status) {
            $locked = SellerOrder::whereKey($sellerOrder->id)->lockForUpdate()->first();
            $locked->update(['status' => $status]);
            return $locked;
        });
    } catch (InsufficientStock $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 422);
    }

    // ── REFUND PICKUP NOTIFICATION ────────────────────────────────────────
    // When a refunded order is marked 'delivered', it means the seller
    // confirmed the returned product was physically picked up.
    if ($status === 'delivered' && $sellerOrder->payment_status === 'refunded') {
        $sellerOrder->loadMissing('order');
        $orderNumber = $sellerOrder->order?->order_number ?? "#{$sellerOrder->id}";

        $seller = \App\Models\User::find($sellerId);
        if ($seller) {
            $seller->notify(
                new \App\Notifications\RefundStatusNotification(
                    'pickup_done',
                    $sellerOrder,
                    $orderNumber
                )
            );
        }
    }

    $this->syncParentOrderStatus($sellerOrder->order_id);

    // The buyer hears about the step once (packed, shipped, delivered, cancelled by the shop…)
    app(\App\Services\Orders\BuyerOrderNotifier::class)->statusChanged(
        (int) $sellerOrder->order_id, [$sellerOrder->id], \App\Services\Orders\BuyerOrderNotifier::REASON_SELLER
    );

    return response()->json([
        'success' => true,
        'message' => __('seller.order.status_updated'),
        'data'    => $sellerOrder,
    ]);
}
    /**
     * Derive and write the correct aggregate status to orders.status
     * based on the current state of all seller_orders for that order.
     */
 private function syncParentOrderStatus(int $orderId): void
{
    $statuses = SellerOrder::where('order_id', $orderId)->pluck('status')->toArray();
    if (empty($statuses)) return;
    $unique = array_unique($statuses);

    $derived = match(true) {
        $unique === ['cancelled']
            => 'cancelled',
        $unique === ['delivered']
            => 'delivered',
        in_array('out_for_delivery', $statuses)
            => 'out_for_delivery',
        count(array_diff($unique, ['completed', 'delivered'])) === 0
            => 'completed',
        in_array('confirmed', $statuses)
            => 'confirmed',   // ← NEW: at least one seller confirmed
        default => 'pending', // ← was 'processing'
    };

    Order::where('id', $orderId)->update(['status' => $derived]);
}
    /* ── PATCH /api/seller/orders/{id}/payment ── */
    /* ── PATCH /api/seller/orders/{id}/payment ── */
public function updatePayment(Request $request, $id)
{
    $request->validate([
        'payment_status' => 'required|in:refunded',
    ]);

    $sellerId    = auth()->id();
    $sellerOrder = SellerOrder::where('seller_id', $sellerId)->findOrFail($id);

    // Block: admin has already confirmed payment — seller cannot override
    if ($sellerOrder->payment_status === 'paid') {
        return response()->json([
            'success' => false,
            'message' => __('seller.order.payment_locked'),
        ], 403);
    }

    // Block: refund only makes sense on delivered/completed orders
    if (!in_array($sellerOrder->status, ['delivered', 'completed'])) {
        return response()->json([
            'success' => false,
            'message' => __('seller.order.refund_after_delivery'),
        ], 422);
    }

    $sellerOrder->update(['payment_status' => 'refunded']);

    // ── REFUND NOTIFICATION ───────────────────────────────────────────────
    $sellerOrder->loadMissing('order');
    $orderNumber = $sellerOrder->order?->order_number ?? "#{$sellerOrder->id}";

    $seller = \App\Models\User::find($sellerId);
    if ($seller) {
        $seller->notify(
            new \App\Notifications\RefundStatusNotification(
                'refunded',
                $sellerOrder,
                $orderNumber
            )
        );
    }

    return response()->json([
        'success' => true,
        'message' => __('seller.order.refunded'),
        'data'    => $sellerOrder,
    ]);
}

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Transform a SellerOrder into the flat shape the seller orders list expects.
     * PRIVACY: email intentionally omitted.
     */
    private function formatSellerOrder(SellerOrder $so): array
    {
        $order = $so->order;
        return [
            'id'              => $so->id,
            'order_number'    => $order?->order_number,
            'status'          => $so->status,
            'display_status'  => $so->display_status,
            'payment_status'  => $so->payment_status,
            'payment_method'  => $order?->payment_method,
            'total_amount'    => round((float) $so->subtotal - (float) ($so->discount_amount ?? 0), 3),
            'subtotal'        => (float) $so->subtotal,
            'discount_amount' => round((float) ($so->discount_amount ?? 0), 3),
            'coupon_code'     => $so->coupon_code,
            'wilaya'          => $order?->wilaya ?? $order?->shipping_address ?? null,
            'created_at'      => $so->created_at,
            'updated_at'      => $so->updated_at,
            'user_id'         => $order?->user_id,
            'user'            => $order?->user ? [
                'id'   => $order->user->id,
                'name' => $order->user->name,
                // 'email' => INTENTIONALLY OMITTED — privacy policy
            ] : null,
            'parent_order_id' => $order?->id,
            'items_count'     => $so->items->count(),   // this seller's items only (already loaded)
        ];
    }
}