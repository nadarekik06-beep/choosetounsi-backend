<?php
// app/Http/Controllers/Admin/FinanceController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Services\FinancialSnapshotService;
use App\Support\Millimes;
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

        // date_from / date_to (Y-m-d) override the period; seller_id narrows everything
        $dateRange = $this->resolveDateRange($period);
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $dateRange = [
                Carbon::parse($request->query('date_from', '2000-01-01'))->startOfDay(),
                Carbon::parse($request->query('date_to', now()->toDateString()))->endOfDay(),
            ];
        }
        $sellerId = $request->filled('seller_id') ? (int) $request->query('seller_id') : null;

        // ── Platform KPIs ────────────────────────────────────────────────────

        $base = DB::table('seller_orders as so')
            ->join('orders as o', 'o.id', '=', 'so.order_id');

        if ($dateRange) {
            $base->whereBetween('so.created_at', $dateRange);
        }
        if ($sellerId) {
            $base->where('so.seller_id', $sellerId);
        }

        $totals = (clone $base)
            ->whereNotIn('so.status', SellerOrder::NOT_SHIPPED)
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

        // ── Ad revenue: paid click charges (plan credit reported apart, it isn't money) ──
        $adFrom    = $dateRange ? \App\Services\Ads\AdClock::dateOf($dateRange[0]) : null;
        $adTo      = $dateRange ? \App\Services\Ads\AdClock::dateOf($dateRange[1]) : null;
        $adMetrics = app(\App\Services\Ads\AdMetrics::class);
        $adRevenue = $adMetrics->platformRevenue($adFrom, $adTo);
        // Cash in: approved ad-wallet top-ups (real money, never plan credit) and plan payments.
        $adTopUps  = $adMetrics->topUpsReceived($adFrom, $adTo);
        $subRevenue = (float) DB::table('subscription_payments')->where('status', 'succeeded')
            ->when($dateRange, fn ($q) => $q->whereBetween('created_at', $dateRange))
            ->sum('amount');

        // ── Pending vs Ready vs Paid ─────────────────────────────────────────

        // Seller payouts only: CHOOSE'Tounsi's own parcels are platform revenue
        $payoutCounts = (clone $base)
            ->selectRaw('so.payout_status, COUNT(*) as cnt, COALESCE(SUM(so.seller_net_amount), 0) as total')
            ->whereNotIn('so.status', SellerOrder::NOT_SHIPPED)
            ->whereRaw('NOT ' . SellerOrder::platformParcelSql('so'))
            ->groupBy('so.payout_status')
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

        $delivery = $this->deliveryKpis(clone $base);
        // The agency fees the platform absorbed on refused parcels are a real loss
        $profit = Millimes::of($totals->total_platform_profit) - Millimes::of($delivery['refused_platform_loss']);

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
                    'total_platform_profit' => Millimes::toFloat($profit),
                    'platform_profit_before_refusals' => round((float) $totals->total_platform_profit, 3),
                    'orders_count'          => (int) $totals->orders_count,
                    'ad_revenue'            => $adRevenue['paid'],
                    'ad_credit_spent'       => $adRevenue['credit'],
                    'ad_top_ups_received'   => $adTopUps['amount'],
                    'ad_top_ups_count'      => $adTopUps['count'],
                    'subscription_revenue'  => round($subRevenue, 3),
                    // Returns refunded to clients in the period, and seller debits not settled yet
                    'returns_refunded'      => round((float) DB::table('complaints')->where('status', 'refunded')
                        ->when($dateRange, fn ($q) => $q->whereBetween('refunded_at', $dateRange))->sum('refund_amount'), 3),
                    'pending_seller_debits' => round((float) DB::table('seller_adjustments')->whereNull('applied_at')->sum('amount'), 3),
                    // Refused at the door: no sale; the agency fee is a platform loss or billed to the seller
                    'refused_parcels'       => [
                        'count'              => $delivery['refused_parcels'],
                        'awaiting_return'    => $delivery['refused_awaiting_return'],
                        'agency_fees_lost'   => $delivery['refused_platform_loss'],
                        'agency_fees_billed' => $delivery['refused_fees_billed_to_sellers'],
                    ],
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
                'delivery'          => $delivery,
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
            ->leftJoin('users as s', 's.id', '=', 'so.seller_id')
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
                // Cancelled sub-orders: no revenue, commission or payout (original_subtotal = history)
                DB::raw("CASE WHEN so.status IN (" . SellerOrder::notShippedSql() . ") THEN 0 ELSE (so.subtotal - so.discount_amount) END as subtotal"),
                DB::raw('(so.subtotal - so.discount_amount) as original_subtotal'),
                'so.discount_amount',
                'so.coupon_code',
                DB::raw("CASE WHEN so.status IN (" . SellerOrder::notShippedSql() . ") THEN 0 ELSE so.commission_amount END as commission_amount"),
                DB::raw("CASE WHEN so.status IN (" . SellerOrder::notShippedSql() . ") THEN 0 ELSE so.seller_net_amount END as seller_net_amount"),
                DB::raw("CASE WHEN so.status IN (" . SellerOrder::notShippedSql() . ") THEN 0 ELSE so.delivery_fee END as delivery_fee"),
                DB::raw("CASE WHEN so.status IN (" . SellerOrder::notShippedSql() . ") THEN 0 ELSE so.shipping_cost END as shipping_cost"),
                DB::raw("CASE WHEN so.status IN (" . SellerOrder::notShippedSql() . ") THEN 0 ELSE so.seller_shipping_charge END as seller_shipping_charge"),
                'o.shipping_paid_by',
                DB::raw("CASE WHEN so.status IN (" . SellerOrder::notShippedSql() . ") THEN 0 ELSE so.platform_profit END as platform_profit"),
                'so.delivery_confirmed_at',
                'so.money_received_at',
                'so.settled_at',
                // Parcel delivery snapshot + cash flow (null on legacy rows)
                'so.is_free_delivery',
                'so.client_delivery_fee',
                'so.agency_delivery_cost',
                'so.seller_free_delivery_contribution',
                DB::raw("CASE WHEN so.status IN (" . SellerOrder::notShippedSql() . ") THEN 0 ELSE so.platform_delivery_margin END as platform_delivery_margin"),
                DB::raw("CASE WHEN so.status IN (" . SellerOrder::notShippedSql() . ") THEN 0 ELSE so.cod_amount END as cod_amount"),
                DB::raw("CASE WHEN so.status IN (" . SellerOrder::notShippedSql() . ") THEN 0 ELSE so.amount_to_remit END as amount_to_remit"),
                'so.cod_amount as original_cod_amount',
                'so.cash_collected_at',
                'so.refused_at',
                'so.refused_agency_fee',
                'so.refused_fee_paid_by',
                'so.returned_to_seller_at',
                'so.carrier_tracking_number',
                DB::raw(SellerOrder::platformParcelSql('so') . ' as is_platform_parcel'),
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
        // Cancelled parcels (never handed to the courier) are hidden unless asked
        // for; 'refused' covers refused parcels before and after their return.
        $status = $request->query('status');
        if ($status === 'refused') {
            $query->whereIn('so.status', SellerOrder::REFUSED);
        } elseif ($status) {
            $query->where('so.status', $status);
        } elseif (!$request->boolean('include_cancelled')) {
            $query->where('so.status', '!=', 'cancelled');
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

        $order     = $so->order;
        $num       = fn($v) => round((float) ($v ?? 0), 3);
        $cancelled = in_array($so->status, SellerOrder::NOT_SHIPPED, true);
        $live      = fn($v) => $cancelled ? 0.0 : $num($v);

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

            $image = $item->displayImageUrl();   // as bought, never another variant's image

            $lineTotal = round((float) $item->total, 3);
            $discount  = round((float) ($item->discount_amount ?? 0), 3);

            return [
                'id'                    => $item->id,
                'product_id'            => $item->product_id,
                'variant_id'            => $item->variant_id,
                'product_name'          => $item->product_name ?: optional($product)->getAttributes()['name'] ?? null,
                'variant_label'         => $item->displayVariantLabel(),
                'variant_options'       => $options,
                'image_url'             => $image,
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
                'display_status'  => $so->display_status,
                'order_status'    => optional($order)->status,
                'payment_method'  => optional($order)->payment_method,
                'payment_status'  => $so->payment_status,
                'payout_status'   => $so->getAttribute('payout_status'),
                'carrier_tracking_number' => $so->getAttribute('carrier_tracking_number'),
                'carrier_status_raw'      => $so->getAttribute('carrier_status_raw'),
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
                // Cancelled: every money figure is 0 ($live); original_gross = history
                'financials' => [
                    'is_cancelled'           => $cancelled,
                    'original_gross'         => $num($so->subtotal - $so->discount_amount),
                    'gross'                  => $live($so->subtotal - $so->discount_amount),
                    'subtotal_before_coupon' => $num($so->subtotal),
                    'discount_amount'        => $num($so->discount_amount),
                    'commission_amount'      => $live($so->getAttribute('commission_amount')),
                    'commission_rate'        => $rate !== null ? (float) $rate : null,
                    'delivery_fee'           => $live($so->getAttribute('delivery_fee')),
                    'shipping_cost'          => $live($so->getAttribute('shipping_cost')),
                    'seller_shipping_charge' => $live($so->getAttribute('seller_shipping_charge')),
                    'shipping_paid_by'       => $order ? $order->getAttribute('shipping_paid_by') : null,
                    'platform_profit'        => $live($so->getAttribute('platform_profit')),
                    'seller_net_amount'      => $live($so->getAttribute('seller_net_amount')),
                    // Parcel delivery snapshot + cash flow (null on legacy rows)
                    'is_free_delivery'                  => $so->getAttribute('is_free_delivery'),
                    'client_delivery_fee'               => $so->getAttribute('client_delivery_fee') !== null ? $num($so->getAttribute('client_delivery_fee')) : null,
                    'agency_delivery_cost'              => $so->getAttribute('agency_delivery_cost') !== null ? $num($so->getAttribute('agency_delivery_cost')) : null,
                    'seller_free_delivery_contribution' => $so->getAttribute('seller_free_delivery_contribution') !== null ? $num($so->getAttribute('seller_free_delivery_contribution')) : null,
                    'platform_delivery_margin'          => $live($so->getAttribute('platform_delivery_margin')),
                    'cod_amount'                        => $live($so->getAttribute('cod_amount')),
                    'amount_to_remit'                   => $live($so->getAttribute('amount_to_remit')),
                    'cash_collected_at'                 => $so->getAttribute('cash_collected_at'),
                    'remittance_received_at'            => $so->getAttribute('money_received_at'),
                    'refused_at'                        => $so->getAttribute('refused_at'),
                    'refused_agency_fee'                => $so->getAttribute('refused_agency_fee') !== null ? $num($so->getAttribute('refused_agency_fee')) : null,
                    'refused_fee_paid_by'               => $so->getAttribute('refused_fee_paid_by'),
                    'returned_to_seller_at'             => $so->getAttribute('returned_to_seller_at'),
                    'is_platform_parcel'                => $so->isPlatformParcel(),
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
            ->whereNotIn('so.status', SellerOrder::NOT_SHIPPED)
            ->whereRaw('NOT ' . SellerOrder::platformParcelSql('so'))
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

        if ($s = $request->query('seller_id')) {
            $query->where('so.seller_id', $s);
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
            ->whereRaw('NOT ' . SellerOrder::platformParcelSql('so'))
            ->when($request->filled('seller_id'), fn ($q) => $q->where('so.seller_id', (int) $request->query('seller_id')))
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
                'message' => 'Cannot confirm the remittance: the parcel must be delivered (cash collected by the courier) and its payout still pending.',
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

    /**
     * Cash on delivery flow over the filtered parcels, from the frozen
     * seller_orders columns only (so it reconciles with the order rows):
     *
     *   cash collected       Σ cod_amount of delivered parcels (cash_collected_at)
     *   agency fees          Σ agency cost the courier kept on them
     *   remitted to us       Σ (cod − agency) whose remittance the admin confirmed
     *   pending at agency    Σ (cod − agency) collected but not remitted yet
     *   reconciliation       cash collected = payouts + commission + agency fees + delivery margin
     *                        (delivered COD parcels without a return: difference must be 0)
     */
    private function deliveryKpis($base): array
    {
        $notPlatform = 'NOT ' . SellerOrder::platformParcelSql('so');
        $live        = (clone $base)->whereNotIn('so.status', SellerOrder::NOT_SHIPPED);
        $collected   = (clone $live)->whereNotNull('so.cash_collected_at')->where('o.payment_method', 'cod');

        $c = (clone $collected)->selectRaw('
            COUNT(*) as parcels,
            COALESCE(SUM(so.cod_amount), 0) as cash,
            COALESCE(SUM(so.shipping_cost), 0) as agency,
            COALESCE(SUM(CASE WHEN so.money_received_at IS NOT NULL THEN so.amount_to_remit ELSE 0 END), 0) as remitted,
            COALESCE(SUM(CASE WHEN so.money_received_at IS NULL THEN so.amount_to_remit ELSE 0 END), 0) as pending
        ')->first();

        // Reconciliation over parcels untouched by returns (a return reverses part of the sale)
        $k = (clone $collected)->whereNull('so.return_status')->selectRaw('
            COALESCE(SUM(so.cod_amount), 0) as cash,
            COALESCE(SUM(so.seller_net_amount), 0) as payouts,
            COALESCE(SUM(so.commission_amount), 0) as commission,
            COALESCE(SUM(so.shipping_cost), 0) as agency,
            COALESCE(SUM(so.platform_delivery_margin), 0) as margin
        ')->first();

        $l = (clone $live)->selectRaw("
            COALESCE(SUM(so.seller_shipping_charge), 0) as contributions,
            COALESCE(SUM(so.platform_delivery_margin), 0) as margin,
            COALESCE(SUM(so.commission_amount), 0) as commission,
            COALESCE(SUM(CASE WHEN {$notPlatform} AND so.payout_status = 'pending' THEN so.seller_net_amount ELSE 0 END), 0) as payouts_not_payable,
            COALESCE(SUM(CASE WHEN {$notPlatform} AND so.payout_status = 'ready' THEN so.seller_net_amount ELSE 0 END), 0) as payouts_payable,
            COALESCE(SUM(CASE WHEN {$notPlatform} AND so.payout_status = 'paid' THEN so.seller_net_amount ELSE 0 END), 0) as payouts_paid,
            COALESCE(SUM(CASE WHEN NOT ({$notPlatform}) THEN so.seller_net_amount ELSE 0 END), 0) as platform_products_net
        ")->first();

        $r = (clone $base)->whereIn('so.status', SellerOrder::REFUSED)->selectRaw("
            COUNT(*) as parcels,
            COALESCE(SUM(so.status = 'refused'), 0) as awaiting_return,
            COALESCE(SUM(CASE WHEN so.refused_fee_paid_by = 'platform' THEN so.refused_agency_fee ELSE 0 END), 0) as platform_loss,
            COALESCE(SUM(CASE WHEN so.refused_fee_paid_by = 'seller' THEN so.refused_agency_fee ELSE 0 END), 0) as billed_to_sellers
        ")->first();

        $m = fn ($v) => Millimes::of($v ?? 0);
        $f = fn (int $v) => Millimes::toFloat($v);
        $explained = $m($k->payouts) + $m($k->commission) + $m($k->agency) + $m($k->margin);

        return [
            'delivered_parcels'                  => (int) $c->parcels,
            'cash_collected'                     => $f($m($c->cash)),
            'agency_fees'                        => $f($m($c->agency)),
            'remitted_to_platform'               => $f($m($c->remitted)),
            'pending_at_delivery_company'        => $f($m($c->pending)),
            'seller_free_delivery_contributions' => $f($m($l->contributions)),
            // Net of the agency fees the platform absorbed on refused parcels
            'platform_delivery_margin'           => $f($m($l->margin) - $m($r->platform_loss)),
            'delivery_margin_before_refusals'    => $f($m($l->margin)),
            'commission'                         => $f($m($l->commission)),
            'seller_payouts_not_payable'         => $f($m($l->payouts_not_payable)),
            'seller_payouts_payable'             => $f($m($l->payouts_payable)),
            'seller_payouts_paid'                => $f($m($l->payouts_paid)),
            'platform_products_net'              => $f($m($l->platform_products_net)),
            'refused_parcels'                    => (int) $r->parcels,
            'refused_awaiting_return'            => (int) $r->awaiting_return,
            'refused_platform_loss'              => $f($m($r->platform_loss)),
            'refused_fees_billed_to_sellers'     => $f($m($r->billed_to_sellers)),
            'reconciliation'                     => [
                'cash_collected' => $f($m($k->cash)),
                'explained'      => $f($explained),
                'difference'     => $f($m($k->cash) - $explained),
            ],
        ];
    }

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