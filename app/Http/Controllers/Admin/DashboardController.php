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

        // ✅ withoutGlobalScopes ensures no hidden filters are applied
        $totalProducts = Product::withoutGlobalScopes()->count();

        $totalOrders = Order::count();

        $pendingSellerApprovals = User::where('role', 'seller')
            ->where('is_approved', false)
            ->where('is_active', true)
            ->count();

        // ✅ Count all unapproved products — no global scope interference
        $pendingProductApprovals = Product::withoutGlobalScopes()
            ->where('is_approved', false)
            ->count();

        try {
            $totalRevenue = Order::whereIn('status', ['delivered', 'completed'])
                ->sum('total_amount');
        } catch (\Exception $e) {
            $totalRevenue = 0;
        }

        // ── Order status distribution ──────────────────────────────────
        try {
            $orderStatusDistribution = Order::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->mapWithKeys(fn($item) => [$item->status => (int)$item->count]);
        } catch (\Exception $e) {
            $orderStatusDistribution = (object)[];
        }

        // ── Monthly revenue ────────────────────────────────────────────
        try {
            $monthlyRevenue = Order::whereIn('status', ['delivered', 'completed'])
                ->where('created_at', '>=', $now->copy()->subMonths(6))
                ->select(
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                    DB::raw('SUM(total_amount) as revenue')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get();
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
                    'total_revenue'             => round((float)$totalRevenue, 3),
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