<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerApplication;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SellerDashboardController extends Controller
{
    private function sellerId(): int
    {
        return (int) auth()->id();
    }

    public function index()
    {
        $now      = Carbon::now();
        $sellerId = $this->sellerId();

        // ── Detect seller column ───────────────────────────────────────────────
        $productCols     = DB::select("SHOW COLUMNS FROM products");
        $productColNames = array_map(fn($c) => $c->Field, $productCols);
        $sellerCol       = in_array('seller_id', $productColNames) ? 'seller_id' : 'user_id';

        // ── Detect order_items columns ─────────────────────────────────────────
        $itemCols     = DB::select("SHOW COLUMNS FROM order_items");
        $itemColNames = array_map(fn($c) => $c->Field, $itemCols);

        // ── Seller's active subscription plan ─────────────────────────────────
        $application = SellerApplication::where('user_id', $sellerId)->first();
        $sellerPlan  = $application?->plan ?? 'free';

        // ── Gross total expression (fallback for pre-commission orders) ────────
        $grossParts = [];
        if (in_array('net_total', $itemColNames))  $grossParts[] = 'oi.net_total'; // after seller coupon
        if (in_array('total', $itemColNames))      $grossParts[] = 'oi.total';
        if (in_array('subtotal', $itemColNames))   $grossParts[] = 'oi.subtotal';
        if (in_array('line_total', $itemColNames)) $grossParts[] = 'oi.line_total';
        if (in_array('price', $itemColNames) && in_array('quantity', $itemColNames)) {
            $grossParts[] = 'oi.price * oi.quantity';
        } elseif (in_array('unit_price', $itemColNames) && in_array('quantity', $itemColNames)) {
            $grossParts[] = 'oi.unit_price * oi.quantity';
        }
        $grossParts[] = '0';
        $grossExpr    = 'COALESCE(' . implode(', ', $grossParts) . ')';

        // ── NET revenue expression — seller's actual earnings after commission ─
        //
        // seller_amount is stored per order_item by CommissionService at checkout.
        // For orders placed before the commission system, seller_amount = 0,
        // so we fall back to the gross total for those legacy rows.
        //
        // COALESCE(NULLIF(seller_amount, 0), gross_fallback):
        //   → uses seller_amount when > 0  (new commission-aware orders)
        //   → falls back to gross total    (pre-commission legacy orders)
        $revenueExpr = in_array('seller_amount', $itemColNames)
            ? "COALESCE(NULLIF(oi.seller_amount, 0), {$grossExpr})"
            : $grossExpr;

        // ── Check if users table has state column ─────────────────────────────
        $userCols     = DB::select("SHOW COLUMNS FROM users");
        $userColNames = array_map(fn($c) => $c->Field, $userCols);
        $hasStateCol  = in_array('state', $userColNames);

        // ── Seller's order IDs ─────────────────────────────────────────────────
        $sellerOrderIds = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->distinct()
            ->pluck('oi.order_id');

        $totalOrders = $sellerOrderIds->count();

        $pendingOrders = $totalOrders > 0
            ? Order::whereIn('id', $sellerOrderIds)->where('status', 'pending')->count()
            : 0;

        // ── Products KPIs ──────────────────────────────────────────────────────
        $totalProducts = Product::withoutGlobalScopes()
            ->where($sellerCol, $sellerId)
            ->count();

        $activeProducts = Product::withoutGlobalScopes()
            ->where($sellerCol, $sellerId)
            ->where('is_active', true)
            ->count();

        $pendingProductApprovals = Product::withoutGlobalScopes()
            ->where($sellerCol, $sellerId)
            ->where('is_approved', false)
            ->count();

        // ── Revenue — NET earnings after platform commission ───────────────────
        $totalRevenue     = 0;
        $revenueThisMonth = 0;
        $revenueLastMonth = 0;

        if ($sellerOrderIds->isNotEmpty()) {
            try {
                $totalRevenue = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o',   'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->sum(DB::raw($revenueExpr));
            } catch (\Exception $e) {
                $totalRevenue = 0;
            }

            try {
                $revenueThisMonth = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o',   'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->whereBetween('o.created_at', [
                        $now->copy()->startOfMonth(),
                        $now->copy()->endOfMonth(),
                    ])
                    ->sum(DB::raw($revenueExpr));
            } catch (\Exception $e) {
                $revenueThisMonth = 0;
            }

            try {
                $revenueLastMonth = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o',   'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->whereBetween('o.created_at', [
                        $now->copy()->subMonth()->startOfMonth(),
                        $now->copy()->subMonth()->endOfMonth(),
                    ])
                    ->sum(DB::raw($revenueExpr));
            } catch (\Exception $e) {
                $revenueLastMonth = 0;
            }
        }

        // ── Shipping the seller pays on free-shipping orders ───────────────────
        // Per order, not per item, so it comes off the item totals afterwards.
        $shippingQuery = fn() => DB::table('seller_orders as so')
            ->join('orders as o', 'o.id', '=', 'so.order_id')
            ->where('so.seller_id', $sellerId)
            ->where('so.status', '!=', 'cancelled')
            ->whereIn('o.status', ['completed', 'delivered']);

        $totalShipping = 0.0;
        $shippingByMonth = collect();
        try {
            $totalShipping     = (float) $shippingQuery()->sum('so.seller_shipping_charge');
            $revenueThisMonth -= (float) $shippingQuery()
                ->whereBetween('o.created_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
                ->sum('so.seller_shipping_charge');
            $revenueLastMonth -= (float) $shippingQuery()
                ->whereBetween('o.created_at', [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()])
                ->sum('so.seller_shipping_charge');
            $totalRevenue     -= $totalShipping;

            $shippingByMonth = $shippingQuery()
                ->where('o.created_at', '>=', $now->copy()->subMonths(11)->startOfMonth())
                ->selectRaw("DATE_FORMAT(o.created_at, '%Y-%m') as month, SUM(so.seller_shipping_charge) as shipping")
                ->groupBy('month')
                ->pluck('shipping', 'month');
        } catch (\Exception $e) {
            // columns missing before the shipping migration — nothing to deduct
        }

        $revenueGrowth = $revenueLastMonth > 0
            ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1)
            : ($revenueThisMonth > 0 ? 100.0 : 0.0);

        // ── Order status distribution ──────────────────────────────────────────
        $orderStatusDistribution = (object) [];
        if ($sellerOrderIds->isNotEmpty()) {
            try {
                $orderStatusDistribution = Order::whereIn('id', $sellerOrderIds)
                    ->select('status', DB::raw('count(*) as count'))
                    ->groupBy('status')
                    ->get()
                    ->mapWithKeys(fn($item) => [$item->status => (int) $item->count]);
            } catch (\Exception $e) {
                $orderStatusDistribution = (object) [];
            }
        }

        // ── Monthly revenue — last 12 months (net) ────────────────────────────
        $monthlyRevenue = collect();
        if ($sellerOrderIds->isNotEmpty()) {
            try {
                $rawMonthly = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o',   'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->where('o.created_at', '>=', $now->copy()->subMonths(11)->startOfMonth())
                    ->select(
                        DB::raw("DATE_FORMAT(o.created_at, '%Y-%m') as month"),
                        DB::raw("SUM({$revenueExpr}) as revenue"),
                        DB::raw('COUNT(DISTINCT oi.order_id) as orders')
                    )
                    ->groupBy('month')
                    ->orderBy('month')
                    ->get()
                    ->keyBy('month');

                for ($i = 11; $i >= 0; $i--) {
                    $key   = $now->copy()->subMonths($i)->format('Y-m');
                    $label = $now->copy()->subMonths($i)->translatedFormat('M Y');
                    $monthlyRevenue->push([
                        'month'   => $label,
                        'revenue' => round((isset($rawMonthly[$key]) ? (float) $rawMonthly[$key]->revenue : 0) - (float) ($shippingByMonth[$key] ?? 0), 3),
                        'orders'  => isset($rawMonthly[$key]) ? (int) $rawMonthly[$key]->orders : 0,
                    ]);
                }
            } catch (\Exception $e) {
                for ($i = 11; $i >= 0; $i--) {
                    $monthlyRevenue->push([
                        'month'   => $now->copy()->subMonths($i)->translatedFormat('M Y'),
                        'revenue' => 0,
                        'orders'  => 0,
                    ]);
                }
            }
        } else {
            for ($i = 11; $i >= 0; $i--) {
                $monthlyRevenue->push([
                    'month'   => $now->copy()->subMonths($i)->translatedFormat('M Y'),
                    'revenue' => 0,
                    'orders'  => 0,
                ]);
            }
        }

        // ── Top 5 clients (by net seller earnings) ────────────────────────────
        $topClients = [];
        if ($sellerOrderIds->isNotEmpty()) {
            try {
                $clientSelect = [
                    'u.id',
                    'u.name',
                    'u.email',
                    DB::raw("SUM({$revenueExpr}) as total_revenue"),
                    DB::raw('COUNT(DISTINCT oi.order_id) as total_orders'),
                ];
                $clientGroup = ['u.id', 'u.name', 'u.email'];

                if ($hasStateCol) {
                    $clientSelect[] = 'u.state';
                    $clientGroup[]  = 'u.state';
                }

                $topClients = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o',   'o.id', '=', 'oi.order_id')
                    ->join('users as u',    'u.id', '=', 'o.user_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->select($clientSelect)
                    ->groupBy($clientGroup)
                    ->orderByDesc('total_revenue')
                    ->limit(5)
                    ->get()
                    ->map(fn($r) => [
                        'id'            => $r->id,
                        'name'          => $r->name,
                        'email'         => $r->email,
                        'state'         => $r->state ?? null,
                        'total_revenue' => round((float) $r->total_revenue, 3),
                        'total_orders'  => (int) $r->total_orders,
                    ]);
            } catch (\Exception $e) {
                $topClients = [];
            }
        }

        // ── Top 5 wilayas (by net seller earnings) ────────────────────────────
        $topWilayas = [];
        if ($sellerOrderIds->isNotEmpty()) {
            try {
                if ($hasStateCol) {
                    $wilayaExpr      = 'COALESCE(NULLIF(o.wilaya, ""), u.state, "Unknown")';
                    $topWilayasQuery = DB::table('order_items as oi')
                        ->join('products as p', 'p.id', '=', 'oi.product_id')
                        ->join('orders as o',   'o.id', '=', 'oi.order_id')
                        ->join('users as u',    'u.id', '=', 'o.user_id')
                        ->where("p.{$sellerCol}", $sellerId)
                        ->whereNull('p.deleted_at')
                        ->whereIn('o.status', ['completed', 'delivered']);
                } else {
                    $wilayaExpr      = 'COALESCE(NULLIF(o.wilaya, ""), "Unknown")';
                    $topWilayasQuery = DB::table('order_items as oi')
                        ->join('products as p', 'p.id', '=', 'oi.product_id')
                        ->join('orders as o',   'o.id', '=', 'oi.order_id')
                        ->where("p.{$sellerCol}", $sellerId)
                        ->whereNull('p.deleted_at')
                        ->whereIn('o.status', ['completed', 'delivered']);
                }

                $topWilayas = $topWilayasQuery
                    ->select(
                        DB::raw("{$wilayaExpr} as wilaya"),
                        DB::raw("SUM({$revenueExpr}) as revenue"),
                        DB::raw('COUNT(DISTINCT oi.order_id) as orders')
                    )
                    ->groupBy('wilaya')
                    ->orderByDesc('revenue')
                    ->limit(5)
                    ->get()
                    ->map(fn($r) => [
                        'wilaya'  => $r->wilaya,
                        'revenue' => round((float) $r->revenue, 3),
                        'orders'  => (int) $r->orders,
                    ]);
            } catch (\Exception $e) {
                $topWilayas = [];
            }
        }

        // ── Recent orders ──────────────────────────────────────────────────────
        $recentOrders = [];
        if ($sellerOrderIds->isNotEmpty()) {
            try {
                $recentOrders = Order::with(['user:id,name,email'])
                    ->whereIn('id', $sellerOrderIds)
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'user_id', 'order_number', 'total_amount', 'status', 'payment_status', 'created_at']);

                // This seller's part only (items − coupon); a cancelled part is worth 0.
                $parts = DB::table('seller_orders')->where('seller_id', $sellerId)
                    ->whereIn('order_id', $recentOrders->pluck('id'))
                    ->get(['order_id', 'status', 'subtotal', 'discount_amount'])
                    ->groupBy('order_id');
                $recentOrders->each(function ($o) use ($parts) {
                    $mine = $parts->get($o->id);
                    if (!$mine) return;   // legacy order without sub-orders: keep the stored total
                    $original  = round($mine->sum(fn($so) => (float) $so->subtotal - (float) $so->discount_amount), 3);
                    $cancelled = $o->status === 'cancelled' || $mine->every(fn($so) => $so->status === 'cancelled');
                    $o->total_amount = $cancelled ? 0.0 : round($mine->where('status', '!=', 'cancelled')
                        ->sum(fn($so) => (float) $so->subtotal - (float) $so->discount_amount), 3);
                    $o->setAttribute('is_cancelled', $cancelled);
                    $o->setAttribute('original_total', $original);
                });
            } catch (\Exception $e) {
                $recentOrders = [];
            }
        }

        // ── Top products (by net seller earnings) ─────────────────────────────
        $topProducts = [];
        try {
            $topProducts = DB::table('order_items as oi')
                ->join('products as p',       'p.id', '=', 'oi.product_id')
                ->join('orders as o',         'o.id', '=', 'oi.order_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->leftJoin('product_images as pi', function ($join) {
                    $join->on('pi.product_id', '=', 'p.id')
                         ->where('pi.is_primary', '=', 1);
                })
                ->where("p.{$sellerCol}", $sellerId)
                ->whereNull('p.deleted_at')
                ->whereIn('o.status', ['completed', 'delivered'])
                ->select(
                    'p.id',
                    'p.name',
                    'p.price',
                    'p.stock',
                    'p.is_active',
                    'p.views',
                    'c.name as category_name',
                    'pi.image_path as primary_image_path',
                    DB::raw('SUM(oi.quantity) as total_sales'),
                    DB::raw("SUM({$revenueExpr}) as total_revenue")
                )
                ->groupBy(
                    'p.id', 'p.name', 'p.price', 'p.stock',
                    'p.is_active', 'p.views', 'c.name', 'pi.image_path'
                )
                ->orderByDesc('total_sales')
                ->limit(6)
                ->get()
                ->map(fn($r) => [
                    'id'                => $r->id,
                    'name'              => $r->name,
                    'price'             => (float) $r->price,
                    'stock'             => (int) $r->stock,
                    'is_active'         => (bool) $r->is_active,
                    'views'             => (int) $r->views,
                    'category_name'     => $r->category_name,
                    'primary_image_url' => $r->primary_image_path
                        ? \Illuminate\Support\Facades\Storage::url($r->primary_image_path)
                        : null,
                    'total_sales'       => (int) $r->total_sales,
                    'total_revenue'     => round((float) $r->total_revenue, 3),
                ]);
        } catch (\Exception $e) {
            $topProducts = [];
        }

        // ── Response ───────────────────────────────────────────────────────────
        return response()->json([
            'success' => true,
            'data'    => [
                'summary' => [
                    'total_revenue'             => round((float) $totalRevenue, 3),   // net of commission and shipping paid by the seller
                    'total_shipping'            => round($totalShipping, 3),
                    'total_orders'              => $totalOrders,
                    'pending_orders'            => $pendingOrders,
                    'total_products'            => $totalProducts,
                    'active_products'           => $activeProducts,
                    'pending_product_approvals' => $pendingProductApprovals,
                    'revenue_this_month'        => round((float) $revenueThisMonth, 3),
                    'revenue_last_month'        => round((float) $revenueLastMonth, 3),
                    'revenue_growth'            => $revenueGrowth,
                    'seller_plan'               => $sellerPlan,
                ],
                'charts' => [
                    'monthly_revenue' => $monthlyRevenue,
                ],
                'order_status_distribution' => $orderStatusDistribution,
                'top_clients'               => $topClients,
                'top_wilayas'               => $topWilayas,
                'top_products'              => $topProducts,
                'recent_orders'             => $recentOrders,
            ],
        ]);
    }
}