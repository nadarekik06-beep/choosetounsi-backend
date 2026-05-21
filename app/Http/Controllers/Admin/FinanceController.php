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
                COALESCE(SUM(so.subtotal), 0)          as gross_revenue,
                COALESCE(SUM(so.commission_amount), 0) as total_commission,
                COALESCE(SUM(so.seller_net_amount), 0) as total_seller_payouts,
                COALESCE(SUM(so.delivery_fee), 0)      as total_delivery_fees,
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
                COALESCE(SUM(subtotal), 0)            as gross,
                COALESCE(SUM(commission_amount), 0)   as commission,
                COALESCE(SUM(delivery_fee), 0)        as delivery_fees,
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
                    'total_delivery_fees'   => round((float) $totals->total_delivery_fees,  3),
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
                'so.subtotal',
                'so.commission_amount',
                'so.seller_net_amount',
                'so.delivery_fee',
                'so.platform_profit',
                'so.delivery_confirmed_at',
                'so.money_received_at',
                'so.settled_at',
                'so.settlement_batch_id',
                'so.created_at',
                DB::raw("CONCAT(o.payment_method) as payment_method"),
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
                DB::raw('COALESCE(SUM(so.subtotal), 0) as gross_revenue'),
                DB::raw('COALESCE(SUM(so.commission_amount), 0) as total_commission'),
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
                'so.subtotal',
                'so.commission_amount',
                'so.seller_net_amount',
                'so.delivery_fee',
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