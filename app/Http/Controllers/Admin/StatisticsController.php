<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StatisticsController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'revenue'      => $this->getRevenueData(),
                'orders'       => $this->getOrdersTrendData(),
                'categories'   => $this->getCategoryData(),
                'users_growth' => $this->getUsersGrowthData(),
            ],
        ]);
    }

    public function revenue()
    {
        return response()->json(['success' => true, 'data' => $this->getRevenueData()]);
    }

    public function ordersTrend()
    {
        return response()->json(['success' => true, 'data' => $this->getOrdersTrendData()]);
    }

    public function categories()
    {
        return response()->json(['success' => true, 'data' => $this->getCategoryData()]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Monthly revenue chart — shows platform COMMISSION earned, not gross order totals.
     *
     * FIX: Previously summed orders.total_amount (the full customer payment).
     * Now sums order_items.commission_amount (what the platform actually keeps).
     * Only paid orders are counted — pending/unpaid orders are not yet income.
     *
     * For orders placed before the commission system was implemented,
     * commission_amount = 0, so they correctly contribute $0 to admin revenue.
     */
    private function getRevenueData(): array
    {
        try {
            $months = collect(range(11, 0))->map(
                fn($i) => Carbon::now()->subMonths($i)->format('Y-m')
            );

            // Check if commission_amount column exists (migration may not be run yet)
            $itemCols    = DB::select("SHOW COLUMNS FROM order_items");
            $itemColNames = array_map(fn($c) => $c->Field, $itemCols);
            $hasCommission = in_array('commission_amount', $itemColNames);

            if ($hasCommission) {
                // ── NEW: sum platform commission from order_items ─────────────
                $revenue = DB::table('order_items as oi')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->where('o.payment_status', 'paid')
                    ->where('o.created_at', '>=', Carbon::now()->subMonths(12))
                    ->select(
                        DB::raw("DATE_FORMAT(o.created_at, '%Y-%m') as month"),
                        DB::raw('SUM(COALESCE(oi.commission_amount, 0)) as total')
                    )
                    ->groupBy('month')
                    ->pluck('total', 'month');
            } else {
                // ── FALLBACK: use gross total if migration not yet run ────────
                $revenue = Order::whereIn('status', ['completed', 'delivered'])
                    ->where('payment_status', 'paid')
                    ->where('created_at', '>=', Carbon::now()->subMonths(12))
                    ->select(
                        DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                        DB::raw('SUM(total_amount) as total')
                    )
                    ->groupBy('month')
                    ->pluck('total', 'month');
            }

            return $months->map(fn($m) => [
                'month'   => $m,
                'revenue' => round((float) ($revenue->get($m, 0)), 3),
            ])->values()->toArray();

        } catch (\Exception $e) {
            return [];
        }
    }

    private function getOrdersTrendData(): array
    {
        try {
            $months = collect(range(5, 0))->map(
                fn($i) => Carbon::now()->subMonths($i)->format('Y-m')
            );

            $orders = Order::where('created_at', '>=', Carbon::now()->subMonths(6))
                ->select(
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                    'status',
                    DB::raw('count(*) as count')
                )
                ->groupBy('month', 'status')
                ->get();

            return $months->map(function ($m) use ($orders) {
                $mo = $orders->where('month', $m);
                return [
                    'month'      => $m,
                    'pending'    => (int) $mo->where('status', 'pending')->sum('count'),
                    'processing' => (int) $mo->where('status', 'processing')->sum('count'),
                    'delivered'  => (int) $mo->where('status', 'delivered')->sum('count'),
                    'canceled'   => (int) $mo->where('status', 'canceled')->sum('count'),
                ];
            })->values()->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getCategoryData(): array
    {
        try {
            return Product::join('categories', 'products.category_id', '=', 'categories.id')
                ->select('categories.name', DB::raw('count(products.id) as count'))
                ->groupBy('categories.name')
                ->orderByDesc('count')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            try {
                return Product::select('category_id', DB::raw('count(*) as count'))
                    ->groupBy('category_id')
                    ->get()
                    ->map(fn($item) => [
                        'name'  => 'Category ' . $item->category_id,
                        'count' => $item->count,
                    ])
                    ->toArray();
            } catch (\Exception $e2) {
                return [];
            }
        }
    }

    private function getUsersGrowthData(): array
    {
        try {
            $months = collect(range(5, 0))->map(
                fn($i) => Carbon::now()->subMonths($i)->format('Y-m')
            );

            $users = User::where('role', 'client')
                ->where('created_at', '>=', Carbon::now()->subMonths(6))
                ->select(
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                    DB::raw('count(*) as count')
                )
                ->groupBy('month')
                ->pluck('count', 'month');

            return $months->map(fn($m) => [
                'month' => $m,
                'users' => (int) ($users->get($m, 0)),
            ])->values()->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }
}