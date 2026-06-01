<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Order;
use App\Http\Controllers\Api\Seller\SellerForecastController;
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
        'revenue'          => (clone $base)
            ->whereIn('status', ['completed', 'delivered'])
            ->sum('subtotal'),
    ]]);
}

    /* ── GET /api/seller/orders ── */
    public function index(Request $request)
    {
        $sellerId = auth()->id();
        $query    = $this->sellerOrderQuery($sellerId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
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
        // ── Determine returned items ──────────────────────────────────────────
// REPLACE WITH:
$returnedItemIds   = collect();
$exchangedItemIds  = collect();
$allItemsReturned  = false;
$allItemsExchanged = false;

$complaints = \App\Models\Complaint::where('order_id', $sellerOrder->order_id)
    ->where('seller_id', $sellerId)
    ->where('status', \App\Models\Complaint::STATUS_APPROVED)
    ->where('refund_status', \App\Models\Complaint::REFUND_STATUS_COMPLETED)
    ->get(['id', 'order_item_ids', 'resolution_type']);

foreach ($complaints as $complaint) {
    $ids        = $complaint->order_item_ids; // array|null
    $isExchange = $complaint->resolution_type === \App\Models\Complaint::RESOLUTION_EXCHANGE;

    if (is_null($ids) || empty($ids)) {
        // Whole-order complaint (legacy NULL or no specific items)
        if ($isExchange) { $allItemsExchanged = true; }
        else             { $allItemsReturned  = true; }
        continue;
    }

    if ($isExchange) {
        $exchangedItemIds = $exchangedItemIds->merge($ids);
    } else {
        $returnedItemIds = $returnedItemIds->merge($ids);
    }
}

$returnedItemIds  = $returnedItemIds->unique()->toArray();
$exchangedItemIds = $exchangedItemIds->unique()->toArray();
        // ── Map items with full variant + commission details ───────────────
$mappedItems = $sellerOrder->items->map(function ($item) use ($returnedItemIds, $allItemsReturned, $exchangedItemIds, $allItemsExchanged) {
            $productName = $item->product_name
                ?? $item->product?->name
                ?? "Product #{$item->product_id}";

            // ── Variant attributes ────────────────────────────────────────
            $variantAttributes = [];
            $variantLabel      = $item->variant_label ?? null;

            if ($item->variant && $item->variant->relationLoaded('attributeOptions')) {
                foreach ($item->variant->attributeOptions as $opt) {
                    $attr = $opt->attribute;
                    if (!$attr) continue;

                    $variantAttributes[] = [
                        'slug'      => $attr->slug,
                        'label'     => $attr->name,
                        'value'     => $opt->value,
                        'color_hex' => $opt->color_hex ?? null,
                    ];
                }

                if (!$variantLabel && !empty($variantAttributes)) {
                    $variantLabel = collect($variantAttributes)
                        ->pluck('value')
                        ->filter()
                        ->join(' / ');
                }
            }

            // ── Variant image resolution ──────────────────────────────────
            // Priority: variant images → color-option images → checkout snapshot → product primary
            $resolvedImage = null;

            // 1. Direct variant_id images
            if ($item->variant && $item->variant->relationLoaded('images')
                && $item->variant->images->isNotEmpty()) {
                $resolvedImage = Storage::url(
                    $item->variant->images->first()->image_path
                );
            }

            // 2. Color-option images
            if (!$resolvedImage && $item->variant) {
                $colorOptionId = $item->variant->color_option_id;
                if ($colorOptionId) {
                    $colorImage = \App\Models\ProductImage::where('product_id', $item->product_id)
                        ->where('color_option_id', $colorOptionId)
                        ->orderBy('order')
                        ->first();
                    if ($colorImage) {
                        $resolvedImage = Storage::url($colorImage->image_path);
                    }
                }
            }

            // 3. Stored checkout snapshot
            if (!$resolvedImage && !empty($item->image_url)) {
                $stored = $item->image_url;
                $resolvedImage = str_starts_with($stored, 'http')
                    ? $stored
                    : url($stored);
            }

            // 4. Product primary image
            if (!$resolvedImage && $item->product) {
                $product = $item->product;
                if ($product->relationLoaded('images')) {
                    $primary = $product->images->firstWhere('is_primary', true)
                             ?? $product->images->sortBy('order')->first();
                    if ($primary) {
                        $resolvedImage = Storage::url($primary->image_path);
                    }
                }
            }

            // ── Commission snapshot fields ────────────────────────────────
            // NEVER recalculate — always read stored values.
            // commission_amount = 0 means legacy order → has_commission = false.
            $commissionAmount = (float) ($item->commission_amount ?? 0);
$isReturned  = $allItemsReturned  || in_array($item->id, $returnedItemIds);
$isExchanged = $allItemsExchanged || in_array($item->id, $exchangedItemIds);
$itemStatus  = $isReturned ? 'returned' : ($isExchanged ? 'exchanged' : null);            $hasCommission    = $commissionAmount > 0;

            return [
                // ── Core fields ───────────────────────────────────────────
                'id'                    => $item->id,
                'product_id'            => $item->product_id,
                'product_name'          => $productName,
                'quantity'              => (int)   $item->quantity,
                'unit_price'            => (float) $item->unit_price,
                'total'                 => (float) $item->total,

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
                // ADD alongside existing fields:
'is_returned' => $isReturned,
'item_status' => $itemStatus,   // 'returned' | 'exchanged' | null
            ];
        });

        // ── Commission order-level totals ─────────────────────────────────
        // Aggregate only from items that have commission data.
        // For legacy orders: has_commission = false, amounts = null.
     $commissionItems = $sellerOrder->items->filter(function ($i) use (
    $returnedItemIds, $allItemsReturned
) {
    // Exclude returned items — their commission was reversed by MarkOrderRefunded
    $isReturned = $allItemsReturned || in_array($i->id, $returnedItemIds);
    return !$isReturned && (float) ($i->commission_amount ?? 0) > 0;
});
$hasAnyCommission = $commissionItems->isNotEmpty();

// seller_subtotal is already adjusted by MarkOrderRefunded (returned items subtracted)
$totalGross            = round((float) $sellerOrder->subtotal, 3);
$totalCommissionAmount = $hasAnyCommission
    ? round($commissionItems->sum(fn($i) => (float) $i->commission_amount), 3)
    : null;
$totalSellerNet        = $hasAnyCommission
    ? round($commissionItems->sum(fn($i) => (float) $i->seller_amount), 3)
    : null;

        $order    = $sellerOrder->order;
        $customer = $order->user ? ['name' => $order->user->name] : null;

        return response()->json([
            'success' => true,
            'data'    => [
                'order' => array_merge($order->toArray(), [
                    'status'          => $sellerOrder->status,
                    'payment_status'  => $sellerOrder->payment_status,
                    'payment_method'  => $order->payment_method,
                    'wilaya'          => $order->wilaya ?? $order->shipping_address ?? null,
                    'customer'        => $customer,
                    'seller_order_id' => $sellerOrder->id,
                ]),
                'items'           => $mappedItems->values(),
                'seller_subtotal' => $totalGross,

                // ── Commission summary block ───────────────────────────────
                // Frontend reads detail.commission.has_commission to decide
                // whether to render the CommissionSummaryCard.
                'commission' => [
                    'has_commission'          => $hasAnyCommission,
                    'total_gross'             => $totalGross,
                    'total_commission_amount' => $totalCommissionAmount,
                    'total_seller_net'        => $totalSellerNet,
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
    $sellerOrder->update(['status' => $status]);

    // ── Forecast cache invalidation ──────────────────────────────────────
    if (in_array($status, ['completed', 'delivered'])) {
        $productIds = $sellerOrder->items()->pluck('product_id')->unique();
        foreach ($productIds as $productId) {
            SellerForecastController::clearForecastCache((int) $productId, (int) $sellerId);
        }
    }

    // ── Create ReviewPrompts when order is delivered ─────────────────────
    if ($status === 'delivered') {
        $this->createReviewPrompts($sellerOrder);
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

    return response()->json([
        'success' => true,
        'message' => 'Order status updated.',
        'data'    => $sellerOrder,
    ]);
}
private function createReviewPrompts(\App\Models\SellerOrder $sellerOrder): void
    {
        try {
            // Eager-load the parent order if not already loaded
            $sellerOrder->loadMissing('order');
 
            $userId = $sellerOrder->order?->user_id;
            if (!$userId) return;
 
            $items = $sellerOrder->items()->get(['id', 'product_id']);
 
            foreach ($items as $item) {
                if (!$item->product_id) continue;
 
                \App\Models\ReviewPrompt::firstOrCreate(
                    [
                        'user_id'       => $userId,
                        'order_item_id' => $item->id,
                    ],
                    [
                        'product_id' => $item->product_id,
                        'sent_at'    => now(),
                        'channel'    => 'popup',
                    ]
                );
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(
                '[ReviewPrompt::createReviewPrompts] ' . $e->getMessage(),
                ['seller_order_id' => $sellerOrder->id]
            );
        }
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
            'message' => 'Payment has already been confirmed by admin and cannot be changed.',
        ], 403);
    }

    // Block: refund only makes sense on delivered/completed orders
    if (!in_array($sellerOrder->status, ['delivered', 'completed'])) {
        return response()->json([
            'success' => false,
            'message' => 'Can only mark as refunded after order is delivered or completed.',
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
        'message' => 'Order marked as refunded.',
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
            'payment_status'  => $so->payment_status,
            'payment_method'  => $order?->payment_method,
            'total_amount'    => (float) $so->subtotal,
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
        ];
    }
}