<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Services\ProductImages;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EarningsController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $sellerId = auth()->id();
        $period   = $request->query('period', 'month');
        $dateRange = $this->resolveDateRange($period);

        $base = DB::table('seller_orders')
            ->where('seller_id', $sellerId)
            ->where('status', '!=', 'cancelled');

        if ($dateRange) {
            $base->whereBetween('created_at', $dateRange);
        }

        $totals = (clone $base)
            ->selectRaw(
                'COALESCE(SUM(subtotal - discount_amount), 0) as gross_revenue,' .
                'COALESCE(SUM(commission_amount), 0) as total_commission,' .
                'COALESCE(SUM(seller_net_amount), 0) as total_net,' .
                'COALESCE(SUM(seller_shipping_charge), 0) as total_shipping,' .
                'COUNT(*) as orders_count,' .
                'COALESCE(SUM(CASE WHEN payout_status = "paid" THEN seller_net_amount ELSE 0 END), 0) as paid_amount,' .
                'COALESCE(SUM(CASE WHEN payout_status = "ready" THEN seller_net_amount ELSE 0 END), 0) as ready_amount,' .
                'COALESCE(SUM(CASE WHEN payout_status = "pending" THEN seller_net_amount ELSE 0 END), 0) as awaiting_cashin_amount,' .
                'COALESCE(SUM(CASE WHEN payout_status IN ("pending","ready") THEN seller_net_amount ELSE 0 END), 0) as pending_amount'
            )
            ->first();

        $daily = DB::table('seller_orders')
            ->where('seller_id', $sellerId)
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->selectRaw(
                'DATE(created_at) as day,' .
                'COUNT(*) as orders,' .
                'COALESCE(SUM(subtotal - discount_amount), 0) as gross,' .
                'COALESCE(SUM(commission_amount), 0) as commission,' .
                'COALESCE(SUM(seller_shipping_charge), 0) as shipping,' .
                'COALESCE(SUM(seller_net_amount), 0) as net_earnings'
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $payoutBreakdown = DB::table('seller_orders')
            ->where('seller_id', $sellerId)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('payout_status, COUNT(*) as cnt, COALESCE(SUM(seller_net_amount), 0) as total')
            ->groupBy('payout_status')
            ->get()
            ->keyBy('payout_status');

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'kpis' => [
                    'gross_revenue'          => round((float) $totals->gross_revenue,          3),
                    'total_commission'       => round((float) $totals->total_commission,       3),
                    // Free-shipping orders: shipping the seller pays, already out of total_net
                    'total_shipping'         => round((float) $totals->total_shipping,         3),
                    'total_net'              => round((float) $totals->total_net,              3),
                    'orders_count'           => (int) $totals->orders_count,
                    'paid_amount'            => round((float) $totals->paid_amount,            3),
                    'pending_amount'         => round((float) $totals->pending_amount,         3),
                    'ready_amount'           => round((float) $totals->ready_amount,           3),
                    'awaiting_cashin_amount' => round((float) $totals->awaiting_cashin_amount, 3),
                ],
                'daily_chart'      => $daily,
                'payout_breakdown' => $payoutBreakdown,
            ],
        ]);
    }
    public function fullReceipt(Request $request): JsonResponse
{
    $sellerId = auth()->id();
    $seller   = auth()->user();

    $sellerProfile = DB::table('seller_applications')
        ->where('user_id', $sellerId)
        ->where('status', 'approved')
        ->orderByDesc('created_at')
        ->first();

    $batches = DB::table('settlement_batches')
        ->where('seller_id', $sellerId)
        ->orderByDesc('batch_date')
        ->get();

    $totals = DB::table('seller_orders')
        ->where('seller_id', $sellerId)
        ->where('status', '!=', 'cancelled')
        ->selectRaw('
            COALESCE(SUM(subtotal - discount_amount), 0) as gross_revenue,
            COALESCE(SUM(commission_amount), 0) as total_commission,
            COALESCE(SUM(seller_net_amount), 0) as total_net,
            COALESCE(SUM(seller_shipping_charge), 0) as total_shipping,
            COUNT(*) as orders_count,
            COALESCE(SUM(CASE WHEN payout_status = "paid"    THEN seller_net_amount ELSE 0 END), 0) as total_paid,
            COALESCE(SUM(CASE WHEN payout_status = "ready"   THEN seller_net_amount ELSE 0 END), 0) as total_ready,
            COALESCE(SUM(CASE WHEN payout_status = "pending" THEN seller_net_amount ELSE 0 END), 0) as total_pending
        ')
        ->first();

    return response()->json([
        'success' => true,
        'data' => [
            'seller' => [
                'name'          => $seller->name,
                'email'         => $seller->email,
                'business_name' => $sellerProfile->business_name
                                   ?? $sellerProfile->store_name
                                   ?? $seller->name,
                'phone'         => $sellerProfile->phone ?? $seller->phone ?? null,
                'wilaya'        => $sellerProfile->wilaya ?? null,
                'city'          => $sellerProfile->city ?? null,
                'plan'          => $seller->plan ?? 'green',
            ],
            'subscription' => [
                'plan'       => $seller->plan ?? 'green',
                'expires_at' => $seller->plan_expires_at ?? null,
            ],
            'totals' => [
                'gross_revenue'    => round((float) $totals->gross_revenue,    3),
                'total_commission' => round((float) $totals->total_commission, 3),
                'total_shipping'   => round((float) $totals->total_shipping,   3),
                'total_net'        => round((float) $totals->total_net,        3),
                'orders_count'     => (int) $totals->orders_count,
                'total_paid'       => round((float) $totals->total_paid,       3),
                'total_ready'      => round((float) $totals->total_ready,      3),
                'total_pending'    => round((float) $totals->total_pending,    3),
            ],
            'batches'      => $batches,
            'generated_at' => now()->toIso8601String(),
        ],
    ]);
}

public function settlementReceipt(Request $request, int $id): JsonResponse
{
    $sellerId = auth()->id();

    $batch = DB::table('settlement_batches as sb')
        ->join('users as u', 'u.id', '=', 'sb.seller_id')
        ->where('sb.id', $id)
        ->where('sb.seller_id', $sellerId) // ← sécurité : le vendeur ne voit que ses propres batches
        ->select(['sb.*', 'u.name as seller_name', 'u.email as seller_email'])
        ->first();

    if (!$batch) {
        return response()->json([
            'success' => false,
            'message' => __('seller.earnings.settlement_not_found'),
        ], 404);
    }

    $orders = DB::table('seller_orders as so')
        ->join('orders as o', 'o.id', '=', 'so.order_id')
        ->where('so.settlement_batch_id', $id)
        ->select([
            'so.id',
            'o.order_number',
            DB::raw('(so.subtotal - so.discount_amount) as subtotal'),
            'so.discount_amount',
            'so.commission_amount',
            'so.seller_net_amount',
            'so.delivery_fee',
            'so.seller_shipping_charge',
            'so.status',
            'so.money_received_at',
            'so.created_at',
        ])
        ->get();

    return response()->json([
        'success' => true,
        'data'    => array_merge((array) $batch, ['orders' => $orders]),
    ]);
}
    public function orders(Request $request): JsonResponse
    {
        $sellerId = auth()->id();

        $query = DB::table('seller_orders as so')
            ->join('orders as o', 'o.id', '=', 'so.order_id')
            ->where('so.seller_id', $sellerId)
            ->select([
                'so.id',
                'o.order_number',
                'so.status',
                'so.payout_status',
                DB::raw('(so.subtotal - so.discount_amount) as gross'),
                'so.discount_amount',
                'so.coupon_code',
                'so.commission_amount',
                'so.seller_net_amount as net_earnings',
                'so.seller_shipping_charge',
                'so.delivery_fee',
                'so.platform_profit',
                'so.money_received_at',
                'so.settled_at',
                'so.settlement_batch_id',
                'so.created_at',
                // Line items of this seller order only (same rows as SellerOrder->items)
                DB::raw('(SELECT COUNT(*) FROM order_items oi WHERE oi.seller_order_id = so.id) as items_count'),
            ]);

        if ($s = $request->query('payout_status')) {
            $query->where('so.payout_status', $s);
        }
        if ($d = $request->query('date_from')) {
            $query->whereDate('so.created_at', '>=', $d);
        }
        if ($d = $request->query('date_to')) {
            $query->whereDate('so.created_at', '<=', $d);
        }

        $results = $query
            ->orderByDesc('so.created_at')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json(['success' => true, 'data' => $results]);
    }

    /**
     * GET /api/seller/earnings/orders/{id}/details   ({id} = seller_orders.id)
     *
     * Read-only drawer data for one of the seller's own orders. Money comes from the
     * same frozen seller_orders columns as orders(), so it always matches the row.
     * PRIVACY: only this seller's items; customer name + wilaya only (what the seller
     * UI already shows); no platform_profit, no agency cost unless charged to the seller.
     */
    public function orderDetails(int $id): JsonResponse
    {
        $so = SellerOrder::where('seller_id', auth()->id())
            ->whereKey($id)
            ->with([
                'order.user:id,name',
                'items.product.images',
                'items.product.variants.attributeOptions.attribute',
                'items.variant.attributeOptions.attribute',
            ])
            ->first();

        if (!$so) {
            return response()->json([
                'success' => false,
                'message' => __('seller.earnings.order_not_found'),
            ], 404);
        }

        $order = $so->order;
        $num   = fn($v) => round((float) ($v ?? 0), 3);

        $items = $so->items->map(function ($item) {
            $product = $item->product;   // withTrashed(); null when hard-deleted
            $variant = $item->variant;   // null when the variant was deleted

            $options = $variant
                ? $variant->attributeOptions
                    ->filter(fn($o) => $o->attribute)
                    ->map(fn($o) => [
                        'name'      => $o->attribute->name,
                        'value'     => $o->value,
                        'color_hex' => $o->color_hex,
                    ])->values()
                : collect();

            $image = $item->getAttributes()['image_url'] ?? null;
            if (!$image && $product) {
                $image = ProductImages::thumbnailFor($product, $variant);
            }

            $lineTotal = round((float) $item->total, 3);
            $discount  = round((float) ($item->discount_amount ?? 0), 3);

            return [
                'id'              => $item->id,
                'product_name'    => $item->product_name ?: ($product ? ($product->getAttributes()['name'] ?? null) : null),
                'variant_label'   => $item->variant_label,
                'variant_options' => $options,
                'image_url'       => $image ? (str_starts_with($image, 'http') ? $image : url($image)) : null,
                'quantity'        => (int) $item->quantity,
                'unit_price'      => round((float) $item->unit_price, 3),
                'line_total'      => $lineTotal,                 // before the coupon
                'discount_amount' => $discount,                  // coupon share on this line
                'paid_total'      => round((float) ($item->net_total ?? ($lineTotal - $discount)), 3),
                'product_deleted' => !$product || $product->trashed(),
                'variant_deleted' => $item->variant_id !== null && !$variant,
            ];
        })->values();

        // Commission %: snapshot on the seller order, else the single rate its items share
        $rate = $so->getAttribute('commission_rate');
        if ($rate === null) {
            $rates = $so->items
                ->filter(fn($i) => (float) $i->commission_amount > 0 && $i->commission_percentage !== null)
                ->map(fn($i) => (float) $i->commission_percentage)
                ->unique();
            $rate = $rates->count() === 1 ? $rates->first() : null;
        }

        // Shipping from the seller's point of view. The agency cost is only shown
        // when it is charged to this seller (then it equals their deduction).
        $charge = $num($so->getAttribute('seller_shipping_charge'));
        $paidBy = $order ? $order->getAttribute('shipping_paid_by') : null;
        $payer  = $charge > 0 || $paidBy === 'seller' ? 'you' : (in_array($paidBy, ['customer', 'platform'], true) ? $paidBy : null);

        $gross      = $num($so->subtotal - $so->discount_amount);
        $commission = $num($so->getAttribute('commission_amount'));
        $net        = $num($so->getAttribute('seller_net_amount'));
        // Non-zero only when the frozen net was adjusted afterwards (e.g. a refund)
        $adjustment = round($net - ($gross - $commission - $charge), 3);

        $timeline = collect([
            ['key' => 'placed',         'at' => $so->created_at],
            ['key' => 'delivered',      'at' => $so->getAttribute('delivery_confirmed_at')],
            ['key' => 'cash_collected', 'at' => $so->getAttribute('money_received_at')],
            ['key' => 'paid_out',       'at' => $so->getAttribute('settled_at')],
        ])->filter(fn($e) => $e['at'])
          ->map(fn($e) => ['key' => $e['key'], 'at' => Carbon::parse($e['at'])->toIso8601String()])
          ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'id'             => $so->id,
                'order_number'   => optional($order)->order_number,
                'created_at'     => $so->created_at,
                'status'         => $so->status,
                'payment_method' => optional($order)->payment_method,
                'payout_status'  => $so->getAttribute('payout_status'),
                'paid_out_at'    => $so->getAttribute('settled_at'),
                'coupon_code'    => $so->coupon_code,
                'items_count'    => $items->count(),
                'customer' => [
                    'name'   => optional(optional($order)->user)->name,
                    'wilaya' => $order ? ($order->wilaya ?? $order->shipping_address ?? null) : null,
                ],
                'items'    => $items,
                'timeline' => $timeline,
                'earnings' => [
                    'gross'                  => $gross,
                    'subtotal_before_coupon' => $num($so->subtotal),
                    'discount_amount'        => $num($so->discount_amount),
                    'commission_amount'      => $commission,
                    'commission_rate'        => $rate !== null ? (float) $rate : null,
                    'shipping_paid_by'       => $payer,     // you | customer | platform | null
                    'shipping_charge'        => $charge,    // your share of the agency cost; 0 unless you pay
                    'adjustment'             => abs($adjustment) >= 0.001 ? $adjustment : 0.0,
                    'net'                    => $net,
                ],
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $sellerId = auth()->id();

        $batches = DB::table('settlement_batches')
            ->where('seller_id', $sellerId)
            ->orderByDesc('batch_date')
            ->paginate((int) $request->query('per_page', 10));

        return response()->json(['success' => true, 'data' => $batches]);
    }

    private function resolveDateRange(string $period): ?array
    {
        if ($period === 'today') {
            return [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()];
        }
        if ($period === 'week') {
            return [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()];
        }
        if ($period === 'month') {
            return [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()];
        }
        return null;
    }
}