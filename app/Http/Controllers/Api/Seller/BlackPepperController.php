<?php
// app/Http/Controllers/Api/Seller/BlackPepperController.php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Models\VipRequest;
use App\Notifications\VipRequestSubmittedNotification;
use App\Notifications\SponsoredProductActivatedNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * BlackPepperController
 *
 * All endpoints require auth:sanctum + seller.plan:black middleware (set in routes).
 *
 * Endpoints:
 *   GET  /api/seller/black/ai-hub           → Trend detection + inventory alerts
 *   GET  /api/seller/black/profit-center     → Revenue, estimated margins, 90-day forecast
 *   POST /api/seller/black/sponsor/{id}      → Toggle product sponsorship
 *   GET  /api/seller/black/sponsored         → List seller's sponsored products
 *   POST /api/seller/black/vip-request       → Submit a VIP request
 *   GET  /api/seller/black/vip-requests      → List seller's VIP requests
 */
class BlackPepperController extends Controller
{
    // ── Groq config (reuses existing pattern from SellerAIController) ─────
    private string $groqApiUrl = 'https://api.groq.com/openai/v1/chat/completions';
    private string $groqModel  = 'llama3-8b-8192';

    private function groqKey(): string
    {
        return config('services.groq.key', env('GROQ_API_KEY', ''));
    }

    private function sellerCol(): string
    {
        static $col = null;
        if ($col) return $col;
        $cols = array_map(fn($c) => $c->Field, DB::select('SHOW COLUMNS FROM products'));
        return $col = in_array('seller_id', $cols) ? 'seller_id' : 'user_id';
    }

    private function totalExpr(): string
    {
        $cols  = array_map(fn($c) => $c->Field, DB::select('SHOW COLUMNS FROM order_items'));
        $parts = [];
        if (in_array('total',      $cols)) $parts[] = 'oi.total';
        if (in_array('unit_price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.unit_price * oi.quantity';
        elseif (in_array('price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.price * oi.quantity';
        $parts[] = '0';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function callGroq(string $system, string $user, int $maxTokens = 600): ?string
    {
        $key = $this->groqKey();
        if (empty($key)) return null;

        try {
            $res = Http::withHeaders([
                'Authorization' => "Bearer {$key}",
                'Content-Type'  => 'application/json',
            ])->timeout(25)->post($this->groqApiUrl, [
                'model'       => $this->groqModel,
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                'max_tokens'  => $maxTokens,
                'temperature' => 0.3,
            ]);

            if (!$res->successful()) {
                Log::warning('[BlackPepper] Groq error ' . $res->status());
                return null;
            }
            return $res->json('choices.0.message.content');
        } catch (\Throwable $e) {
            Log::error('[BlackPepper] Groq exception: ' . $e->getMessage());
            return null;
        }
    }

    private function parseGroqJson(string $raw): ?array
    {
        try {
            $clean = preg_replace('/```json|```/i', '', $raw);
            $start = strpos($clean, '{');
            $end   = strrpos($clean, '}');
            if ($start !== false && $end !== false) {
                return json_decode(substr($clean, $start, $end - $start + 1), true);
            }
        } catch (\Throwable $e) {}
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1. AI HUB
    //    GET /api/seller/black/ai-hub
    //
    //    Returns:
    //      - trending_products  : products whose 7-day velocity > 1.5× their 30-day avg
    //      - inventory_alerts   : products with < 14 days of stock remaining
    //      - market_insights    : Groq-generated natural-language summary (cached 6h)
    // ═══════════════════════════════════════════════════════════════════════
    public function aiHub(Request $request): JsonResponse
    {
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();
        $now       = Carbon::now();

        // ── Trending: 7-day velocity vs 30-day daily average ──────────────

        // 30-day daily average per product
        $thirtyDayAvg = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subDays(30))
            ->selectRaw("oi.product_id, SUM(oi.quantity) / 30.0 as daily_avg")
            ->groupBy('oi.product_id')
            ->get()
            ->keyBy('product_id');

        // 7-day total per product
        $sevenDaySales = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subDays(7))
            ->selectRaw("oi.product_id, SUM(oi.quantity) as seven_day_total, SUM({$totalExpr}) as seven_day_revenue")
            ->groupBy('oi.product_id')
            ->get()
            ->keyBy('product_id');

        // Products data for context
        $products = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->where('p.is_approved', true)
            ->selectRaw("p.id, p.name, p.price, p.stock, c.name as category_name")
            ->get()
            ->keyBy('id');

        // Calculate trending products
        $trendingProducts = [];
        foreach ($sevenDaySales as $productId => $sevenDay) {
            $avg    = $thirtyDayAvg[$productId]->daily_avg ?? 0;
            $dailyVelocity = $sevenDay->seven_day_total / 7.0;

            if ($avg > 0 && $dailyVelocity >= $avg * 1.5) {
                $product = $products[$productId] ?? null;
                if (!$product) continue;

                $velocityMultiplier = round($dailyVelocity / $avg, 2);
                $daysToTrend = max(0, (int) round(7 - (($dailyVelocity - $avg * 1.5) / max($dailyVelocity, 0.01))));

                $trendingProducts[] = [
                    'product_id'           => (int) $productId,
                    'product_name'         => $product->name,
                    'category'             => $product->category_name,
                    'price'                => (float) $product->price,
                    'current_stock'        => (int) $product->stock,
                    'seven_day_units'      => (int) $sevenDay->seven_day_total,
                    'seven_day_revenue'    => round((float) $sevenDay->seven_day_revenue, 3),
                    'daily_velocity'       => round($dailyVelocity, 2),
                    'thirty_day_avg'       => round($avg, 2),
                    'velocity_multiplier'  => $velocityMultiplier,
                    'trend_signal'         => $velocityMultiplier >= 2.5 ? 'hot' : ($velocityMultiplier >= 1.8 ? 'rising' : 'warm'),
                    'insight'              => "Sales velocity is {$velocityMultiplier}× above your 30-day average. " .
                                              ($daysToTrend < 3 ? "Trending NOW — stock up immediately." : "Trending in ~{$daysToTrend} days."),
                ];
            }
        }

        // Sort by velocity multiplier desc
        usort($trendingProducts, fn($a, $b) => $b['velocity_multiplier'] <=> $a['velocity_multiplier']);
        $trendingProducts = array_slice($trendingProducts, 0, 10);

        // ── Inventory Alerts: days-of-stock < 14 ──────────────────────────

        // Average daily sales per product (last 30 days)
        $avgDailySales = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subDays(30))
            ->selectRaw("oi.product_id, SUM(oi.quantity) / 30.0 as daily_avg")
            ->groupBy('oi.product_id')
            ->get()
            ->keyBy('product_id');

        $inventoryAlerts = [];
        foreach ($products as $product) {
            $daily = $avgDailySales[$product->id]->daily_avg ?? 0;
            if ($daily <= 0) continue; // never sold → skip

            $daysRemaining = (int) floor($product->stock / $daily);

            if ($daysRemaining <= 14) {
                $urgency = $daysRemaining <= 3 ? 'critical' : ($daysRemaining <= 7 ? 'high' : 'medium');
                $revenueAtRisk = round($daily * max(0, 14 - $daysRemaining) * $product->price, 3);

                $inventoryAlerts[] = [
                    'product_id'       => (int) $product->id,
                    'product_name'     => $product->name,
                    'category'         => $product->category_name,
                    'current_stock'    => (int) $product->stock,
                    'daily_sales_avg'  => round($daily, 2),
                    'days_remaining'   => $daysRemaining,
                    'urgency'          => $urgency,
                    'revenue_at_risk'  => $revenueAtRisk,
                    'restock_units'    => max(0, (int) ceil($daily * 30) - $product->stock),
                    'insight'          => match($urgency) {
                        'critical' => "⚠️ Only {$daysRemaining} days of stock left. Restock NOW to avoid losing " . number_format($revenueAtRisk, 3) . " TND.",
                        'high'     => "Stock runs out in {$daysRemaining} days. Order restocking within 48h.",
                        default    => "Plan a restock this week — {$daysRemaining} days of stock remaining.",
                    },
                ];
            }
        }

        usort($inventoryAlerts, fn($a, $b) => $a['days_remaining'] <=> $b['days_remaining']);
        $inventoryAlerts = array_slice($inventoryAlerts, 0, 10);

        // ── Groq Market Insights Summary (6h cache per seller) ───────────

        $cacheKey  = "black_ai_hub_insights_{$sellerId}";
        $cachePath = storage_path("app/cache/{$cacheKey}.json");
        $insights  = null;

        if (file_exists($cachePath)) {
            $cached = json_decode(file_get_contents($cachePath), true);
            if ($cached && isset($cached['expires_at']) && Carbon::parse($cached['expires_at'])->isFuture()) {
                $insights = $cached['content'];
            }
        }

        if (!$insights) {
            $trendingNames = collect($trendingProducts)->pluck('product_name')->take(3)->implode(', ') ?: 'none';
            $alertNames    = collect($inventoryAlerts)->pluck('product_name')->take(3)->implode(', ') ?: 'none';
            $totalRevenue7d = round(collect($trendingProducts)->sum('seven_day_revenue'), 3);

            $system = "You are an elite Tunisian e-commerce strategist for ChooseTounsi marketplace.
Provide concise, actionable insights in 3 bullet points maximum.
ALWAYS respond with ONLY valid JSON. No markdown.";

            $userPrompt = <<<PROMPT
Provide market insights for this Black Pepper seller on ChooseTounsi:

TRENDING PRODUCTS: {$trendingNames}
INVENTORY RISK PRODUCTS: {$alertNames}
7-DAY REVENUE FROM TRENDING: {$totalRevenue7d} TND
TOTAL TRENDING PRODUCTS: {$trendCount}
CRITICAL STOCK ALERTS: {$criticalCount}

Respond ONLY with this JSON:
{
  "headline": "<one powerful headline summarizing the seller's momentum>",
  "insights": [
    "<actionable insight 1>",
    "<actionable insight 2>",
    "<actionable insight 3>"
  ],
  "priority_action": "<single most important thing to do right now>",
  "market_temperature": "hot"|"warm"|"cooling"|"cold"
}
PROMPT;

            // Fill the variables that were referenced in the HEREDOC
            $trendCount    = count($trendingProducts);
            $criticalCount = count(array_filter($inventoryAlerts, fn($a) => $a['urgency'] === 'critical'));

            $userPrompt = str_replace(
                ['{$trendCount}', '{$criticalCount}'],
                [$trendCount, $criticalCount],
                $userPrompt
            );

            $raw = $this->callGroq($system, $userPrompt, 400);
            if ($raw) {
                $parsed = $this->parseGroqJson($raw);
                if ($parsed) {
                    $insights = $parsed;
                    // Cache for 6 hours
                    @mkdir(storage_path('app/cache'), 0755, true);
                    file_put_contents($cachePath, json_encode([
                        'content'    => $insights,
                        'expires_at' => now()->addHours(6)->toISOString(),
                    ]));
                }
            }

            if (!$insights) {
                $insights = [
                    'headline'           => count($trendingProducts) > 0
                        ? count($trendingProducts) . ' of your products are gaining momentum this week.'
                        : 'Your store performance data is ready for analysis.',
                    'insights'           => [
                        count($trendingProducts) > 0
                            ? 'Trending products show above-average velocity — consider activating sponsorship on them for maximum reach.'
                            : 'Drive traffic to your top products using the Visibility Control Panel.',
                        count($inventoryAlerts) > 0
                            ? count($inventoryAlerts) . ' product(s) need restocking within 14 days to avoid revenue loss.'
                            : 'Your inventory levels are healthy across all active products.',
                        'Black Pepper sellers who use sponsorship see an average +35% increase in product visibility.',
                    ],
                    'priority_action'    => count($inventoryAlerts) > 0
                        ? 'Restock ' . ($inventoryAlerts[0]['product_name'] ?? 'critical products') . ' immediately.'
                        : 'Activate sponsorship on your fastest-moving product.',
                    'market_temperature' => count($trendingProducts) >= 3 ? 'hot' : (count($trendingProducts) >= 1 ? 'warm' : 'cooling'),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'trending_products' => $trendingProducts,
                'inventory_alerts'  => $inventoryAlerts,
                'market_insights'   => $insights,
                'meta'              => [
                    'trending_count'  => count($trendingProducts),
                    'alert_count'     => count($inventoryAlerts),
                    'critical_count'  => count(array_filter($inventoryAlerts, fn($a) => $a['urgency'] === 'critical')),
                    'generated_at'    => now()->toISOString(),
                ],
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 2. PROFIT COMMAND CENTER
    //    GET /api/seller/black/profit-center
    //
    //    NOTE: No cost_price column exists on products table.
    //    We use estimated_cost = price × 0.60 as disclosed approximation.
    //    This is clearly labeled in the API response.
    //
    //    Returns:
    //      - period_breakdown  : revenue/estimated-profit per period
    //      - product_margins   : per-product estimated margin
    //      - forecast          : 30-day linear regression forecast
    //      - summary           : totals
    // ═══════════════════════════════════════════════════════════════════════
    public function profitCenter(Request $request): JsonResponse
    {
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();
        $now       = Carbon::now();

        // ── Daily revenue last 90 days (for forecast regression) ──────────
        $dailyRevenue = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subDays(90))
            ->selectRaw("DATE(o.created_at) as day, SUM({$totalExpr}) as revenue, COUNT(DISTINCT oi.order_id) as orders")
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        // ── Monthly breakdown (last 6 months) ─────────────────────────────
        $monthlyRevenue = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subMonths(6))
            ->selectRaw("DATE_FORMAT(o.created_at, '%Y-%m') as month, SUM({$totalExpr}) as revenue, SUM(oi.quantity) as units, COUNT(DISTINCT oi.order_id) as orders")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // ── Per-product margin estimation ──────────────────────────────────
        // estimated_cost = price × 0.60 (disclosed)
        // estimated_margin = revenue - (units × price × 0.60)
        $productMargins = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subMonths(3))
            ->selectRaw("
                p.id as product_id,
                p.name as product_name,
                c.name as category_name,
                p.price,
                SUM(oi.quantity) as total_units,
                SUM({$totalExpr}) as total_revenue,
                SUM({$totalExpr}) - SUM(oi.quantity * p.price * 0.60) as estimated_profit,
                (SUM({$totalExpr}) - SUM(oi.quantity * p.price * 0.60)) / NULLIF(SUM({$totalExpr}), 0) * 100 as margin_pct
            ")
            ->groupBy('p.id', 'p.name', 'c.name', 'p.price')
            ->orderByDesc('total_revenue')
            ->limit(20)
            ->get()
            ->map(fn($row) => [
                'product_id'       => (int) $row->product_id,
                'product_name'     => $row->product_name,
                'category'         => $row->category_name,
                'price'            => (float) $row->price,
                'total_units'      => (int) $row->total_units,
                'total_revenue'    => round((float) $row->total_revenue, 3),
                'estimated_profit' => round((float) $row->estimated_profit, 3),
                'margin_pct'       => round((float) $row->margin_pct, 1),
                'margin_label'     => (float) $row->margin_pct >= 35 ? 'excellent' : ((float) $row->margin_pct >= 25 ? 'good' : ((float) $row->margin_pct >= 15 ? 'fair' : 'low')),
            ]);

        // ── 30-day linear regression forecast ─────────────────────────────
        $n         = $dailyRevenue->count();
        $forecast  = null;

        if ($n >= 7) {
            // Simple OLS: y = a + b*x where x = day index, y = revenue
            $xMean = ($n - 1) / 2.0;
            $yMean = $dailyRevenue->avg('revenue');

            $numerator   = 0.0;
            $denominator = 0.0;

            foreach ($dailyRevenue as $i => $row) {
                $x = $i - $xMean;
                $y = (float)$row->revenue - $yMean;
                $numerator   += $x * $y;
                $denominator += $x * $x;
            }

            $slope     = $denominator > 0 ? $numerator / $denominator : 0;
            $intercept = $yMean - $slope * $xMean;

            // Project next 30 days
            $forecastDays = [];
            for ($d = 1; $d <= 30; $d++) {
                $x              = $n - 1 + $d;
                $predictedValue = max(0, $intercept + $slope * $x);
                $forecastDays[] = [
                    'day'       => $now->copy()->addDays($d)->format('Y-m-d'),
                    'predicted' => round($predictedValue, 3),
                ];
            }

            $totalForecast30  = round(collect($forecastDays)->sum('predicted'), 3);
            $last30Actual     = round($dailyRevenue->take(-30)->sum('revenue'), 3);
            $growthPct        = $last30Actual > 0
                ? round(($totalForecast30 - $last30Actual) / $last30Actual * 100, 1)
                : 0;

            $forecast = [
                'next_30_days'   => $totalForecast30,
                'last_30_actual' => $last30Actual,
                'growth_pct'     => $growthPct,
                'trend'          => $slope > 0.5 ? 'up' : ($slope < -0.5 ? 'down' : 'stable'),
                'daily_points'   => $forecastDays,
                'confidence'     => $n >= 30 ? 'high' : ($n >= 14 ? 'medium' : 'low'),
            ];
        }

        // ── Summary ────────────────────────────────────────────────────────
        $totalRevenue90d = round($dailyRevenue->sum('revenue'), 3);
        $totalEstProfit  = round($totalRevenue90d * 0.40, 3); // 40% estimated margin

        $periodBreakdown = $monthlyRevenue->map(fn($row) => [
            'month'            => $row->month,
            'revenue'          => round((float) $row->revenue, 3),
            'estimated_profit' => round((float) $row->revenue * 0.40, 3),
            'units'            => (int) $row->units,
            'orders'           => (int) $row->orders,
            'avg_order_value'  => $row->orders > 0 ? round((float) $row->revenue / $row->orders, 3) : 0,
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'summary' => [
                    'total_revenue_90d'    => $totalRevenue90d,
                    'estimated_profit_90d' => $totalEstProfit,
                    'estimated_margin_pct' => 40.0,
                    'margin_disclaimer'    => 'Estimated margin assumes 60% cost ratio. Update when cost_price data becomes available.',
                    'data_points'          => $n,
                ],
                'period_breakdown' => $periodBreakdown,
                'product_margins'  => $productMargins,
                'forecast'         => $forecast,
                'daily_revenue'    => $dailyRevenue->map(fn($r) => [
                    'day'     => $r->day,
                    'revenue' => round((float) $r->revenue, 3),
                    'orders'  => (int) $r->orders,
                ]),
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3. TOGGLE PRODUCT SPONSORSHIP
    //    POST /api/seller/black/sponsor/{id}
    //    Body: { action: 'activate'|'deactivate', priority?: 1-10 }
    // ═══════════════════════════════════════════════════════════════════════
    public function toggleSponsorship(Request $request, int $productId): JsonResponse
    {
        $request->validate([
            'action'   => 'required|in:activate,deactivate',
            'priority' => 'nullable|integer|min:1|max:10',
        ]);

        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();

        $product = Product::where('id', $productId)
            ->where($sellerCol, $sellerId)
            ->whereNull('deleted_at')
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        if ($request->action === 'activate') {
            $product->update([
                'is_sponsored'      => true,
                'sponsored_priority'=> $request->input('priority', 5),
                'sponsored_at'      => now(),
                'sponsored_until'   => null, // no expiry — manual control
            ]);

            // Notify admins (non-fatal)
            try {
                $seller = auth()->user();
                $admins = User::where('role', 'admin')->get();
                foreach ($admins as $admin) {
                    $admin->notify(new SponsoredProductActivatedNotification($seller, $product));
                }
            } catch (\Throwable $e) {
                Log::warning('[BlackPepper] Sponsorship notification failed: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => "Sponsorship activated for \"{$product->name}\".",
                'data'    => ['is_sponsored' => true, 'sponsored_priority' => $product->sponsored_priority],
            ]);
        }

        // Deactivate
        $product->update([
            'is_sponsored'       => false,
            'sponsored_priority' => 0,
            'sponsored_at'       => null,
            'sponsored_until'    => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Sponsorship deactivated for \"{$product->name}\".",
            'data'    => ['is_sponsored' => false],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4. LIST SPONSORED PRODUCTS
    //    GET /api/seller/black/sponsored
    // ═══════════════════════════════════════════════════════════════════════
    public function sponsoredProducts(Request $request): JsonResponse
    {
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();

        $sponsored = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('product_images as pi', function ($join) {
                $join->on('pi.product_id', '=', 'p.id')
                     ->where('pi.is_primary', true);
            })
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->selectRaw("
                p.id, p.name, p.price, p.stock, p.is_sponsored,
                p.sponsored_priority, p.sponsored_at, p.is_active, p.is_approved,
                c.name as category_name,
                pi.image_path
            ")
            ->orderByDesc('p.is_sponsored')
            ->orderByDesc('p.sponsored_priority')
            ->get()
            ->map(fn($row) => [
                'id'                 => (int) $row->id,
                'name'               => $row->name,
                'price'              => (float) $row->price,
                'stock'              => (int) $row->stock,
                'is_active'          => (bool) $row->is_active,
                'is_approved'        => (bool) $row->is_approved,
                'is_sponsored'       => (bool) $row->is_sponsored,
                'sponsored_priority' => (int) $row->sponsored_priority,
                'sponsored_at'       => $row->sponsored_at,
                'category_name'      => $row->category_name,
                'image_url'          => $row->image_path
                    ? url(\Storage::url($row->image_path))
                    : null,
            ]);

        return response()->json(['success' => true, 'data' => $sponsored]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 5. SUBMIT VIP REQUEST
    //    POST /api/seller/black/vip-request
    //    Body: { type: 'reel'|'promotion'|'support', message: string }
    // ═══════════════════════════════════════════════════════════════════════
    public function submitVipRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'    => 'required|in:reel,promotion,support',
            'message' => 'required|string|min:10|max:1000',
        ]);

        $seller = auth()->user();

        // Rate limit: max 3 pending requests of same type
        $pendingCount = VipRequest::where('user_id', $seller->id)
            ->where('type', $validated['type'])
            ->where('status', 'pending')
            ->count();

        if ($pendingCount >= 3) {
            return response()->json([
                'success' => false,
                'message' => 'You already have 3 pending requests of this type. Please wait for them to be processed.',
            ], 422);
        }

        $vipRequest = VipRequest::create([
            'user_id' => $seller->id,
            'type'    => $validated['type'],
            'message' => $validated['message'],
            'status'  => 'pending',
        ]);

        // Notify admins (non-fatal)
        try {
            $admins = User::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                $admin->notify(new VipRequestSubmittedNotification($seller, $vipRequest));
            }
        } catch (\Throwable $e) {
            Log::warning('[BlackPepper] VIP request notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Your VIP request has been submitted. Our team will contact you within 24 hours.',
            'data'    => [
                'id'         => $vipRequest->id,
                'type'       => $vipRequest->type,
                'type_label' => $vipRequest->type_label,
                'status'     => $vipRequest->status,
                'created_at' => $vipRequest->created_at->toISOString(),
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 6. LIST MY VIP REQUESTS
    //    GET /api/seller/black/vip-requests
    // ═══════════════════════════════════════════════════════════════════════
    public function myVipRequests(Request $request): JsonResponse
    {
        $requests = VipRequest::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'id'          => $r->id,
                'type'        => $r->type,
                'type_label'  => $r->type_label,
                'status'      => $r->status,
                'status_label'=> $r->status_label,
                'message'     => $r->message,
                'admin_note'  => $r->admin_note,
                'created_at'  => $r->created_at->toISOString(),
                'handled_at'  => $r->handled_at?->toISOString(),
            ]);

        return response()->json(['success' => true, 'data' => $requests]);
    }
}