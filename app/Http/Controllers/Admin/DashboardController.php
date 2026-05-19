<?php
// app/Http/Controllers/Admin/DashboardController.php

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
        $totalOrders  = Order::count();

        $pendingSellerApprovals = User::where('role', 'seller')
            ->where('is_approved', false)
            ->where('is_active', true)
            ->count();

        $pendingProductApprovals = Product::withoutGlobalScopes()
            ->where('is_approved', false)
            ->count();

        // ── Total Revenue ──────────────────────────────────────────────
        //
        // Reads from seller_orders (same source as FinanceController)
        // to stay in sync with the Finance dashboard.
        //
        // gross_revenue  = what customers paid (seller_orders.subtotal)
        // platform_profit = commission + delivery fee (what platform earns)
        //
        // We show gross_revenue on the dashboard KPI so the number is
        // meaningful to the admin (matches Finance > Overview > Gross Revenue).
        //
        // Excludes cancelled orders — same filter as FinanceController.
        try {
            $revenueRow = DB::table('seller_orders')
                ->where('status', '!=', 'cancelled')
                ->selectRaw('
                    COALESCE(SUM(subtotal), 0)          as gross_revenue,
                    COALESCE(SUM(platform_profit), 0)   as platform_profit,
                    COALESCE(SUM(commission_amount), 0) as total_commission
                ')
                ->first();

$totalRevenue = round((float) ($revenueRow->platform_profit ?? 0), 3);        } catch (\Exception $e) {
            // Fallback if seller_orders columns don't exist yet
            $totalRevenue = round((float) Order::where('status', '!=', 'cancelled')->sum('total_amount'), 3);
        }

        // ── Order count — match Finance (non-cancelled seller_orders) ──
        try {
            $totalOrders = DB::table('seller_orders')
                ->where('status', '!=', 'cancelled')
                ->count();
        } catch (\Exception $e) {
            $totalOrders = Order::count();
        }

        // ── Order status distribution ──────────────────────────────────
        // Read from seller_orders so pie chart matches real fulfillment state
        try {
            $orderStatusDistribution = DB::table('seller_orders')
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->mapWithKeys(fn($item) => [$item->status => (int) $item->count]);
        } catch (\Exception $e) {
            $orderStatusDistribution = (object) [];
        }

        // ── Monthly revenue (last 6 months) ───────────────────────────
        // Gross revenue per month from seller_orders — matches Finance chart
        try {
            $monthlyRevenue = DB::table('seller_orders')
                ->where('status', '!=', 'cancelled')
                ->where('created_at', '>=', $now->copy()->subMonths(6))
                ->select(
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                    DB::raw('COALESCE(SUM(subtotal), 0) as revenue')
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
                    'total_orders'              => (int) $totalOrders,
                    'total_revenue'             => $totalRevenue,
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