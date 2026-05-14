<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Order;
use App\Http\Controllers\Api\Seller\SellerForecastController;

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
                'items.product.images',
                'items.variant.attributeOptions.attribute',
                'items.variant.images',
            ]);
    }

    /* ── GET /api/seller/orders/stats ── */
    public function stats(Request $request)
    {
        $sellerId = auth()->id();
        $base     = SellerOrder::where('seller_id', $sellerId);

        return response()->json(['success' => true, 'data' => [
            'total'     => (clone $base)->count(),
            'pending'   => (clone $base)->where('status', 'pending')->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'delivered' => (clone $base)->where('status', 'delivered')->count(),
            'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
            'revenue'   => (clone $base)
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

        // ── Map items with full variant + commission details ───────────────
        $mappedItems = $sellerOrder->items->map(function ($item) {

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
            $hasCommission    = $commissionAmount > 0;

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
            ];
        });

        // ── Commission order-level totals ─────────────────────────────────
        // Aggregate only from items that have commission data.
        // For legacy orders: has_commission = false, amounts = null.
        $commissionItems  = $sellerOrder->items->filter(
            fn($i) => (float) ($i->commission_amount ?? 0) > 0
        );
        $hasAnyCommission = $commissionItems->isNotEmpty();

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

    /* ── PATCH /api/seller/orders/{id}/status ── */
    public function updateStatus(Request $request, $id)
{
    $request->validate([
        'status' => 'required|in:pending,processing,completed,delivered,cancelled',
    ]);

    $sellerId    = auth()->id();
    $sellerOrder = SellerOrder::where('seller_id', $sellerId)->findOrFail($id);

    $status = $request->status;
    $sellerOrder->update(['status' => $status]);

    if (in_array($status, ['completed', 'delivered'])) {
        $productIds = $sellerOrder->items()->pluck('product_id')->unique();
        foreach ($productIds as $productId) {
            SellerForecastController::clearForecastCache((int) $productId, (int) $sellerId);
        }
    }

    $this->syncParentOrderStatus($sellerOrder->order_id);

    return response()->json([
        'success' => true,
        'message' => 'Order status updated.',
        'data'    => $sellerOrder,
    ]);
}

    /**
     * Derive and write the correct aggregate status to orders.status
     * based on the current state of all seller_orders for that order.
     */
    private function syncParentOrderStatus(int $orderId): void
    {
        $statuses = SellerOrder::where('order_id', $orderId)
            ->pluck('status')
            ->toArray();

        if (empty($statuses)) return;

        $unique = array_unique($statuses);

        $derived = match(true) {
            $unique === ['cancelled']
                => 'cancelled',
            $unique === ['delivered']
                => 'delivered',
            count(array_diff($unique, ['completed', 'delivered'])) === 0
                => 'completed',
            default => 'processing',
        };

        Order::where('id', $orderId)->update(['status' => $derived]);
    }

    /* ── PATCH /api/seller/orders/{id}/payment ── */
    public function updatePayment(Request $request, $id)
    {
        $request->validate([
            'payment_status' => 'required|in:unpaid,paid,refunded',
        ]);

        $sellerId    = auth()->id();
        $sellerOrder = SellerOrder::where('seller_id', $sellerId)->findOrFail($id);
        $sellerOrder->update(['payment_status' => $request->payment_status]);

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
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