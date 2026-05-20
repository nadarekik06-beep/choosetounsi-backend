<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
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
                'COALESCE(SUM(subtotal), 0) as gross_revenue,' .
                'COALESCE(SUM(commission_amount), 0) as total_commission,' .
                'COALESCE(SUM(seller_net_amount), 0) as total_net,' .
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
                'COALESCE(SUM(subtotal), 0) as gross,' .
                'COALESCE(SUM(commission_amount), 0) as commission,' .
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
            COALESCE(SUM(subtotal), 0) as gross_revenue,
            COALESCE(SUM(commission_amount), 0) as total_commission,
            COALESCE(SUM(seller_net_amount), 0) as total_net,
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
            'message' => 'Settlement not found.',
        ], 404);
    }

    $orders = DB::table('seller_orders as so')
        ->join('orders as o', 'o.id', '=', 'so.order_id')
        ->where('so.settlement_batch_id', $id)
        ->select([
            'so.id',
            'o.order_number',
            'so.subtotal',
            'so.commission_amount',
            'so.seller_net_amount',
            'so.delivery_fee',
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