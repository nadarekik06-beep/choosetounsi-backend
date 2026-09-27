<?php
// app/Http/Controllers/Admin/FinanceController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Services\FinancialSnapshotService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FinanceController — Admin financial reconciliation dashboard.
 *
 * Routes:
 *   GET  /api/admin/finance/overview          — KPIs + daily summary
 *   GET  /api/admin/finance/orders            — per-order financial breakdown
 *   GET  /api/admin/finance/sellers           — per-seller earnings tracking
 *   GET  /api/admin/finance/pending-payouts   — orders ready to settle
 *   GET  /api/admin/finance/orders/{id}/details — one seller order's items + breakdown
 *   POST /api/admin/finance/confirm-money/{id} — mark cash received from delivery
 */
class FinanceController extends Controller
{
    public function __construct(private FinancialSnapshotService $snapshot) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/finance/overview
    // ─────────────────────────────────────────────────────────────────────────

    public function overview(Request $request): JsonResponse
    {
        $period = $request->query('period', 'today'); // today | week | month | all

        $dateRange = $this->resolveDateRange($period);

        // ── Platform KPIs ────────────────────────────────────────────────────

        $base = DB::table('seller_orders as so')
            ->join('orders as o', 'o.id', '=', 'so.order_id');

        if ($dateRange) {
            $base->whereBetween('so.created_at', $dateRange);
        }

        $totals = (clone $base)
            ->where('so.status', '!=', 'cancelled')
            ->selectRaw('
                COALESCE(SUM(so.subtotal - so.discount_amount), 0) as gross_revenue,
                COALESCE(SUM(so.commission_amount), 0) as total_commission,
                COALESCE(SUM(so.seller_net_amount), 0) as total_seller_payouts,
                COALESCE(SUM(so.delivery_fee), 0)      as total_delivery_fees,
                COALESCE(SUM(so.seller_shipping_charge), 0) as total_seller_shipping,
                COALESCE(SUM(so.shipping_cost), 0)     as total_shipping_cost,
                COALESCE(SUM(so.platform_profit), 0)   as total_platform_profit,
                COUNT(DISTINCT so.id)                  as orders_count
            ')
            ->first();

        // ── Pending vs Ready vs Paid ─────────────────────────────────────────

        $payoutCounts = DB::table('seller_orders')
            ->selectRaw('payout_status, COUNT(*) as cnt, COALESCE(SUM(seller_net_amount), 0) as total')
            ->where('status', '!=', 'cancelled')
            ->groupBy('payout_status')
            ->get()
            ->keyBy('payout_status');

        // ── Daily collections (last 7 days) ──────────────────────────────────

        $dailyCollections = DB::table('seller_orders')
            ->where('status', 'delivered')
            ->whereNotNull('money_received_at')
            ->where('money_received_at', '>=', Carbon::now()->subDays(7))
            ->selectRaw('
                DATE(money_received_at)               as collection_date,
                COUNT(*)                              as orders,
                COALESCE(SUM(subtotal - discount_amount), 0) as gross,
                COALESCE(SUM(commission_amount), 0)   as commission,
                COALESCE(SUM(delivery_fee), 0)        as delivery_fees,
                COALESCE(SUM(seller_shipping_charge), 0) as seller_shipping,
                COALESCE(SUM(shipping_cost), 0)       as shipping_cost,
                COALESCE(SUM(seller_net_amount), 0)   as seller_payouts,
                COALESCE(SUM(platform_profit), 0)     as platform_profit
            ')
            ->groupBy('collection_date')
            ->orderByDesc('collection_date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'kpis' => [
                    'gross_revenue'         => round((float) $totals->gross_revenue,        3),
                    'total_commission'      => round((float) $totals->total_commission,     3),
                    'total_seller_payouts'  => round((float) $totals->total_seller_payouts, 3),
                    // Shipping: collected from customers + deducted from sellers
                    // (free shipping) − paid to the agency. Net is inside platform_profit.
                    'total_delivery_fees'   => round((float) $totals->total_delivery_fees,  3),
                    'total_seller_shipping' => round((float) $totals->total_seller_shipping, 3),
                    'total_shipping_cost'   => round((float) $totals->total_shipping_cost,  3),
                    'total_platform_profit' => round((float) $totals->total_platform_profit,3),
                    'orders_count'          => (int) $totals->orders_count,
                ],
                'payout_summary' => [
                    'pending' => [
                        'count'  => (int) ($payoutCounts->get('pending')->cnt   ?? 0),
                        'amount' => round((float) ($payoutCounts->get('pending')->total  ?? 0), 3),
                    ],
                    'ready' => [
                        'count'  => (int) ($payoutCounts->get('ready')->cnt     ?? 0),
                        'amount' => round((float) ($payoutCounts->get('ready')->total    ?? 0), 3),
                    ],
                    'paid' => [
                        'count'  => (int) ($payoutCounts->get('paid')->cnt      ?? 0),
                        'amount' => round((float) ($payoutCounts->get('paid')->total     ?? 0), 3),
                    ],
                ],
                'daily_collections' => $dailyCollections,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/finance/orders
    // ─────────────────────────────────────────────────────────────────────────

    public function orders(Request $request): JsonResponse
    {
        $query = DB::table('seller_orders as so')
            ->join('orders as o', 'o.id', '=', 'so.order_id')
            ->join('users as s', 's.id', '=', 'so.seller_id')
            ->leftJoin('users as admin', 'admin.id', '=', 'so.money_received_by')
            ->leftJoin('seller_applications as sa', function ($join) {
                $join->on('sa.user_id', '=', 'so.seller_id')
                    ->where('sa.status', '=', 'approved');
            })
            ->select([
                'so.id',
                'so.order_id',
                'so.seller_id',
                'o.order_number',
                's.name as seller_name',
                's.email as seller_email',
                'sa.phone_number as seller_phone',
                'so.status',
                'so.payout_status',
                'so.payment_status',
                DB::raw('(so.subtotal - so.discount_amount) as subtotal'),
                'so.discount_amount',
                'so.coupon_code',
                'so.commission_amount',
                'so.seller_net_amount',
                'so.delivery_fee',
                'so.shipping_cost',
                'so.seller_shipping_charge',
                'o.shipping_paid_by',
                'so.platform_profit',
                'so.delivery_confirmed_at',
                'so.money_received_at',
                'so.settled_at',
                'so.settlement_batch_id',
                'so.created_at',
                DB::raw("CONCAT(o.payment_method) as payment_method"),
                // Line items of this seller's part only (same rows as SellerOrder->items)
                DB::raw('(SELECT COUNT(*) FROM order_items oi WHERE oi.seller_order_id = so.id) as items_count'),
            ]);

        // Filters
        if ($s = $request->query('seller_id')) {
            $query->where('so.seller_id', $s);
        }
        if ($s = $request->query('payout_status')) {
            $query->where('so.payout_status', $s);
        }
        if ($d = $request->query('date_from')) {
            $query->whereDate('so.created_at', '>=', $d);
        }
        if ($d = $request->query('date_to')) {
            $query->whereDate('so.created_at', '<=', $d);
        }
        if ($s = $request->query('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('o.order_number', 'like', "%$s%")
                  ->orWhere('s.name',       'like', "%$s%");
            });
        }

        $results = $query
            ->orderByDesc('so.created_at')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json(['success' => true, 'data' => $results]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/finance/orders/{id}/details   ({id} = seller_orders.id)
    // Read-only. Financials come from the same frozen seller_orders columns as
    // the orders() row, so the drawer always matches the table.
    // ─────────────────────────────────────────────────────────────────────────

    public function orderDetails(int $id): JsonResponse
    {
        $so = SellerOrder::with([
            'order.user:id,name,email',
            'seller:id,name,email',
            'items.product.images',
            'items.product.variants.attributeOptions.attribute',
            'items.variant.attributeOptions.attribute',
        ])->find($id);

        if (!$so) {
            return response()->json(['success' => false, 'message' => 'Seller order not found.'], 404);
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

            $image = null;
            if (!empty($item->getAttributes()['image_url'])) {
                $image = $item->getAttributes()['image_url'];
            } elseif ($product) {
                $image = \App\Services\ProductImages::thumbnailFor($product, $variant);
            }

            $lineTotal = round((float) $item->total, 3);
            $discount  = round((float) ($item->discount_amount ?? 0), 3);

            return [
                'id'                    => $item->id,
                'product_id'            => $item->product_id,
                'variant_id'            => $item->variant_id,
                'product_name'          => $item->product_name ?: optional($product)->getAttributes()['name'] ?? null,
                'variant_label'         => $item->variant_label,
                'variant_options'       => $options,
                'image_url'             => $image ? (str_starts_with($image, 'http') ? $image : url($image)) : null,
                'quantity'              => (int) $item->quantity,
                'unit_price'            => round((float) $item->unit_price, 3),
                'line_total'            => $lineTotal,                       // before the seller's coupon
                'discount_amount'       => $discount,                        // coupon share on this line
                'paid_total'            => round((float) ($item->net_total ?? ($lineTotal - $discount)), 3),
                'commission_percentage' => $item->commission_percentage !== null ? (float) $item->commission_percentage : null,
                'commission_amount'     => round((float) ($item->commission_amount ?? 0), 3),
                'product_deleted'       => !$product || $product->trashed(),
                'variant_deleted'       => $item->variant_id !== null && !$variant,
            ];
        })->values();

        // Commission %: snapshot on the seller order, else the single rate its items share
        $rate = $so->getAttribute('commission_rate');
        if ($rate === null) {
            $rates = $items->where('commission_amount', '>', 0)->pluck('commission_percentage')->filter(fn($r) => $r !== null)->unique();
            $rate  = $rates->count() === 1 ? $rates->first() : null;
        }

        $sellerPhone = DB::table('seller_applications')
            ->where('user_id', $so->seller_id)
            ->where('status', 'approved')
            ->value('phone_number');

        return response()->json([
            'success' => true,
            'data'    => [
                'id'              => $so->id,
                'order_id'        => $so->order_id,
                'order_number'    => optional($order)->order_number,
                'created_at'      => $so->created_at,
                'status'          => $so->status,
                'order_status'    => optional($order)->status,
                'payment_method'  => optional($order)->payment_method,
                'payment_status'  => $so->payment_status,
                'payout_status'   => $so->getAttribute('payout_status'),
                'coupon_code'     => $so->coupon_code,
                'items_count'     => $items->count(),
                'seller' => [
                    'id'    => $so->seller_id,
                    'name'  => optional($so->seller)->name,
                    'email' => optional($so->seller)->email,
                    'phone' => $sellerPhone,
                ],
                'customer' => [
                    'id'      => optional($order)->user_id,
                    'name'    => optional(optional($order)->user)->name,
                    'email'   => optional(optional($order)->user)->email,
                    'phone'   => optional($order)->phone,
                    'address' => optional($order)->address,
                    'wilaya'  => optional($order)->wilaya,
                ],
                'items'      => $items,
                // Same columns / formula as the orders() row
                'financials' => [
                    'gross'                  => $num($so->subtotal - $so->discount_amount),
                    'subtotal_before_coupon' => $num($so->subtotal),
                    'discount_amount'        => $num($so->discount_amount),
                    'commission_amount'      => $num($so->getAttribute('commission_amount')),
                    'commission_rate'        => $rate !== null ? (float) $rate : null,
                    'delivery_fee'           => $num($so->getAttribute('delivery_fee')),
                    'shipping_cost'          => $num($so->getAttribute('shipping_cost')),
                    'seller_shipping_charge' => $num($so->getAttribute('seller_shipping_charge')),
                    'shipping_paid_by'       => $order ? $order->getAttribute('shipping_paid_by') : null,
                    'platform_profit'        => $num($so->getAttribute('platform_profit')),
                    'seller_net_amount'      => $num($so->getAttribute('seller_net_amount')),
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/finance/sellers
    // ─────────────────────────────────────────────────────────────────────────

    public function sellers(Request $request): JsonResponse
    {
        $query = DB::table('seller_orders as so')
            ->join('users as u', 'u.id', '=', 'so.seller_id')
            ->leftJoin('seller_applications as sa', function ($join) {
                $join->on('sa.user_id', '=', 'so.seller_id')
                    ->where('sa.status', '=', 'approved');
            })
            ->where('so.status', '!=', 'cancelled')
            ->groupBy('so.seller_id', 'u.name', 'u.email', 'sa.phone_number')
            ->select([
                'so.seller_id',
                'u.name as seller_name',
                'u.email as seller_email',
                'sa.phone_number as seller_phone',
                DB::raw('COUNT(so.id) as orders_count'),
                DB::raw('COALESCE(SUM(so.subtotal - so.discount_amount), 0) as gross_revenue'),
                DB::raw('COALESCE(SUM(so.commission_amount), 0) as total_commission'),
                DB::raw('COALESCE(SUM(so.seller_shipping_charge), 0) as total_shipping'),
                DB::raw('COALESCE(SUM(so.seller_net_amount), 0) as total_net'),
                DB::raw('COALESCE(SUM(CASE WHEN so.payout_status = "paid" THEN so.seller_net_amount ELSE 0 END), 0) as total_paid_out'),
                DB::raw('COALESCE(SUM(CASE WHEN so.payout_status = "ready" THEN so.seller_net_amount ELSE 0 END), 0) as pending_payout'),
            ]);

        if ($d = $request->query('date_from')) {
            $query->whereDate('so.created_at', '>=', $d);
        }
        if ($d = $request->query('date_to')) {
            $query->whereDate('so.created_at', '<=', $d);
        }
        if ($s = $request->query('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('u.name',           'like', "%$s%")
                  ->orWhere('sa.phone_number', 'like', "%$s%");
            });
        }

        $results = $query
            ->orderByDesc('total_net')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json(['success' => true, 'data' => $results]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/finance/pending-payouts
    // ─────────────────────────────────────────────────────────────────────────

    public function pendingPayouts(Request $request): JsonResponse
    {
        $results = DB::table('seller_orders as so')
            ->join('orders as o', 'o.id', '=', 'so.order_id')
            ->join('users as s', 's.id', '=', 'so.seller_id')
            ->leftJoin('seller_applications as sa', function ($join) {
                $join->on('sa.user_id', '=', 'so.seller_id')
                    ->where('sa.status', '=', 'approved');
            })
            ->where('so.payout_status', 'ready')
            ->whereNull('so.settlement_batch_id')
            ->select([
                'so.id',
                'o.order_number',
                's.name as seller_name',
                's.email as seller_email',
                'sa.phone_number as seller_phone',
                'so.seller_id',
                DB::raw('(so.subtotal - so.discount_amount) as subtotal'),
                'so.discount_amount',
                'so.coupon_code',
                'so.commission_amount',
                'so.seller_net_amount',
                'so.delivery_fee',
                'so.seller_shipping_charge',
                'so.money_received_at',
                'so.created_at',
            ])
            ->orderBy('so.money_received_at')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json(['success' => true, 'data' => $results]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/admin/finance/confirm-money/{sellerOrderId}
    // ─────────────────────────────────────────────────────────────────────────

    public function confirmMoneyReceived(Request $request, int $id): JsonResponse
    {
        $adminId = auth()->id();

        $success = $this->snapshot->confirmMoneyReceived($id, $adminId);

        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot confirm money: order must be delivered and payout must be pending.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Money receipt confirmed. Order is now ready for settlement.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveDateRange(string $period): ?array
    {
        return match ($period) {
            'today' => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()],
            'week'  => [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()],
            'month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            default => null,
        };
    }
}