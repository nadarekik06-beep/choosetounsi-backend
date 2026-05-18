<?php
// app/Http/Controllers/Api/Seller/EarningsController.php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EarningsController — Seller earnings dashboard.
 *
 * Routes:
 *   GET /api/seller/earnings/overview   — KPIs + daily chart
 *   GET /api/seller/earnings/orders     — per-order breakdown with commission
 *   GET /api/seller/earnings/history    — full settlement history
 */
class EarningsController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/seller/earnings/overview
    // ─────────────────────────────────────────────────────────────────────────

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

        // ── KPIs ────────────────────────────────────────────────────────────
        $totals = (clone $base)
            ->selectRaw('
                COALESCE(SUM(subtotal), 0)          as gross_revenue,
                COALESCE(SUM(commission_amount), 0) as total_commission,
                COALESCE(SUM(seller_net_amount), 0) as total_net,
                COUNT(*)                            as orders_count,
                COALESCE(SUM(CASE WHEN payout_status = "paid"  THEN seller_net_amount ELSE 0 END), 0) as paid_amount,
                COALESCE(SUM(CASE WHEN payout_status = "ready" THEN seller_net_amount ELSE 0 END), 0) as ready_amount,
                COALESCE(SUM(CASE WHEN payout_status = "pending" THEN seller_net_amount ELSE 0 END), 0) as pending_amount
            ')
            ->first();

        // ── Daily net earnings (last 30 days) ────────────────────────────────
        $daily = DB::table('seller_orders')
            ->where('seller_id', $sellerId)
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->selectRaw('
                DATE(created_at)                    as day,
                COUNT(*)                            as orders,
                COALESCE(SUM(subtotal), 0)          as gross,
                COALESCE(SUM(commission_amount), 0) as commission,
                COALESCE(SUM(seller_net_amount), 0) as net_earnings
            ')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        // ── Payout status breakdown ───────────────────────────────────────────
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
                    'gross_revenue'    => round((float) $totals->gross_revenue,   3),
                    'total_commission' => round((float) $totals->total_commission,3),
                    'total_net'        => round((float) $totals->total_net,       3),
                    'orders_count'     => (int) $totals->orders_count,
                    'paid_amount'      => round((float) $totals->paid_amount,     3),
                    'ready_amount'     => round((float) $totals->ready_amount,    3),
                    'pending_amount'   => round((float) $totals->pending_amount,  3),
                ],
                'daily_chart'      => $daily,
                'payout_breakdown' => $payoutBreakdown,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/seller/earnings/orders
    // ─────────────────────────────────────────────────────────────────────────

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
                'so.subtotal as gross',
                'so.commission_amount',
                'so.seller_net_amount as net_earnings',
                'so.delivery_fee',
                'so.platform_profit',
                'so.money_received_at',
                'so.settled_at',
                'so.settlement_batch_id',
                'so.created_at',
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

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/seller/earnings/history
    // ─────────────────────────────────────────────────────────────────────────

    public function history(Request $request): JsonResponse
    {
        $sellerId = auth()->id();

        $batches = DB::table('settlement_batches')
            ->where('seller_id', $sellerId)
            ->orderByDesc('batch_date')
            ->paginate((int) $request->query('per_page', 10));

        return response()->json(['success' => true, 'data' => $batches]);
    }

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