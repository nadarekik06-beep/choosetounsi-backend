<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $now = Carbon::now();

        // ── KPIs ──────────────────────────────────────────────────────
        $totalUsers   = User::where('role', 'client')->count();
        $totalSellers = User::where('role', 'seller')->count();

        $totalProducts = Product::withoutGlobalScopes()->count();

        $totalOrders = Order::count();

        $pendingSellerApprovals = User::where('role', 'seller')
            ->where('is_approved', false)
            ->where('is_active', true)
            ->count();

        $pendingProductApprovals = Product::withoutGlobalScopes()
            ->where('is_approved', false)
            ->count();

        // ── Check if commission_amount column exists ───────────────────
        // Safe guard in case migration hasn't run on some environment.
        try {
            $itemCols      = DB::select("SHOW COLUMNS FROM order_items");
            $itemColNames  = array_map(fn($c) => $c->Field, $itemCols);
            $hasCommission = in_array('commission_amount', $itemColNames);
        } catch (\Exception $e) {
            $hasCommission = false;
        }

        // ── Total Revenue = platform commission from paid orders ───────
        //
        // FIX: Previously summed orders.total_amount (customer's full payment).
        // Now sums order_items.commission_amount (what the platform actually earns).
        // Only paid orders count — unpaid/pending orders are not yet income.
        //
        // Fallback: if migration hasn't run, use gross total_amount so the
        // dashboard doesn't break (shows an inflated number but doesn't crash).
        try {
            if ($hasCommission) {
                $totalRevenue = DB::table('order_items as oi')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->whereIn('o.status', ['delivered', 'completed'])
                    ->where('o.payment_status', 'paid')
                    ->sum('oi.commission_amount');
            } else {
                // Legacy fallback
                $totalRevenue = Order::whereIn('status', ['delivered', 'completed'])
                    ->where('payment_status', 'paid')
                    ->sum('total_amount');
            }
        } catch (\Exception $e) {
            $totalRevenue = 0;
        }

        // ── Order status distribution ──────────────────────────────────
        try {
            $orderStatusDistribution = Order::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->mapWithKeys(fn($item) => [$item->status => (int) $item->count]);
        } catch (\Exception $e) {
            $orderStatusDistribution = (object) [];
        }

        // ── Monthly revenue (last 6 months) = commission earned ────────
        //
        // FIX: Previously summed orders.total_amount.
        // Now sums order_items.commission_amount per month so the Revenue
        // Overview chart on the admin dashboard shows platform profit only.
        try {
            if ($hasCommission) {
                $monthlyRevenue = DB::table('order_items as oi')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->whereIn('o.status', ['delivered', 'completed'])
                    ->where('o.payment_status', 'paid')
                    ->where('o.created_at', '>=', $now->copy()->subMonths(6))
                    ->select(
                        DB::raw("DATE_FORMAT(o.created_at, '%Y-%m') as month"),
                        DB::raw('SUM(COALESCE(oi.commission_amount, 0)) as revenue')
                    )
                    ->groupBy('month')
                    ->orderBy('month')
                    ->get();
            } else {
                // Legacy fallback
                $monthlyRevenue = Order::whereIn('status', ['delivered', 'completed'])
                    ->where('created_at', '>=', $now->copy()->subMonths(6))
                    ->select(
                        DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                        DB::raw('SUM(total_amount) as revenue')
                    )
                    ->groupBy('month')
                    ->orderBy('month')
                    ->get();
            }
        } catch (\Exception $e) {
            $monthlyRevenue = [];
        }

        // ── Recent orders ──────────────────────────────────────────────
        try {
            $recentOrders = Order::with(['user:id,name,email'])
                ->latest()
                ->limit(5)
                ->get();
        } catch (\Exception $e) {
            $recentOrders = [];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'kpis' => [
                    'total_users'               => $totalUsers,
                    'total_sellers'             => $totalSellers,
                    'total_products'            => $totalProducts,
                    'total_orders'              => $totalOrders,
                    'total_revenue'             => round((float) $totalRevenue, 3),
                    'pending_seller_approvals'  => $pendingSellerApprovals,
                    'pending_product_approvals' => $pendingProductApprovals,
                ],
                'order_status_distribution' => $orderStatusDistribution,
                'monthly_revenue'           => $monthlyRevenue,
                'recent_orders'             => $recentOrders,
            ],
        ]);
    }
}