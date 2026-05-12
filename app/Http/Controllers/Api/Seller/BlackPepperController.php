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
use App\Services\FunnelInsightService;
use App\Services\ProductQualityService;
use App\Services\AutoPromotionService;
use Illuminate\Support\Facades\Cache;

/**
 * BlackPepperController  — Phases 1, 2 & 3 complete
 *
 * Endpoints:
 *   GET  /api/seller/black/ai-hub
 *   GET  /api/seller/black/daily-brief
 *   GET  /api/seller/black/profit-center
 *   GET  /api/seller/black/funnel-insights
 *   GET  /api/seller/black/quality-audit
 *   GET  /api/seller/black/auto-promote-suggestions   ← Phase 3
 *   POST /api/seller/black/sponsor/{id}
 *   GET  /api/seller/black/sponsored
 *   POST /api/seller/black/vip-request
 *   GET  /api/seller/black/vip-requests
 */
class BlackPepperController extends Controller
{
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

    private function humanVelocityLabel(float $multiplier): string
    {
        if ($multiplier >= 3) return "Selling " . round($multiplier) . "x faster than usual";
        if ($multiplier >= 2) return "Selling twice as fast as usual";
        return "Selling 1.5x faster than usual";
    }

    private function humanUrgencyLabel(string $urgency, int $daysRemaining): string
    {
        if ($urgency === 'critical') {
            return "Act today — only {$daysRemaining} day" . ($daysRemaining === 1 ? '' : 's') . " left";
        }
        if ($urgency === 'high') return "Restock within 48 hours";
        return "Plan a restock this week";
    }

    // =========================================================================
    // 1. AI HUB
    //    GET /api/seller/black/ai-hub
    // =========================================================================
    public function aiHub(Request $request): JsonResponse
    {
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();
        $now       = Carbon::now();

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

        $products = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('product_images as pi', function ($join) {
                $join->on('pi.product_id', '=', 'p.id')
                    ->where('pi.is_primary', true)
                    ->whereNull('pi.variant_id');
            })
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->where('p.is_approved', true)
            ->selectRaw("p.id, p.name, p.price, p.stock, c.name as category_name, MIN(pi.image_path) as image_path")
            ->groupBy('p.id', 'p.name', 'p.price', 'p.stock', 'c.name')
            ->get()
            ->keyBy('id');

        $trendingProducts = [];
        foreach ($sevenDaySales as $productId => $sevenDay) {
            $avg           = $thirtyDayAvg[$productId]->daily_avg ?? 0;
            $dailyVelocity = $sevenDay->seven_day_total / 7.0;

            if ($avg > 0 && $dailyVelocity >= $avg * 1.5) {
                $product = $products[$productId] ?? null;
                if (!$product) continue;

                $velocityMultiplier = round($dailyVelocity / $avg, 2);
                $daysToTrend        = max(0, (int) round(7 - (($dailyVelocity - $avg * 1.5) / max($dailyVelocity, 0.01))));

                $trendingProducts[] = [
                    'product_id'          => (int) $productId,
                    'product_name'        => $product->name,
                    'category'            => $product->category_name,
                    'price'               => (float) $product->price,
                    'current_stock'       => (int) $product->stock,
                    'seven_day_units'     => (int) $sevenDay->seven_day_total,
                    'seven_day_revenue'   => round((float) $sevenDay->seven_day_revenue, 3),
                    'daily_velocity'      => round($dailyVelocity, 2),
                    'thirty_day_avg'      => round($avg, 2),
                    'velocity_multiplier' => $velocityMultiplier,
                    'velocity_label'      => $this->humanVelocityLabel($velocityMultiplier),
                    'trend_signal'        => $velocityMultiplier >= 2.5 ? 'hot' : ($velocityMultiplier >= 1.8 ? 'rising' : 'warm'),
                    'insight'             => $this->humanVelocityLabel($velocityMultiplier) . ". " .
                                            ($daysToTrend < 3
                                                ? "This is your hottest product right now."
                                                : "This product is gaining momentum."),
                    'smart_actions'       => [
                        ['label' => 'Promote Now', 'href' => '/seller/promote', 'type' => 'promote'],
                    ],
                    'image_url'           => $product->image_path
                        ? url(\Storage::url($product->image_path))
                        : null,
                ];
            }
        }

        usort($trendingProducts, fn($a, $b) => $b['velocity_multiplier'] <=> $a['velocity_multiplier']);
        $trendingProducts = array_slice($trendingProducts, 0, 10);

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
            if ($daily <= 0) continue;

            $daysRemaining = (int) floor($product->stock / $daily);

            if ($daysRemaining <= 14) {
                $urgency       = $daysRemaining <= 3 ? 'critical' : ($daysRemaining <= 7 ? 'high' : 'medium');
                $revenueAtRisk = round($daily * max(0, 14 - $daysRemaining) * $product->price, 3);

                $inventoryAlerts[] = [
                    'product_id'      => (int) $product->id,
                    'product_name'    => $product->name,
                    'category'        => $product->category_name,
                    'current_stock'   => (int) $product->stock,
                    'daily_sales_avg' => round($daily, 2),
                    'days_remaining'  => $daysRemaining,
                    'urgency'         => $urgency,
                    'urgency_label'   => $this->humanUrgencyLabel($urgency, $daysRemaining),
                    'revenue_at_risk' => $revenueAtRisk,
                    'restock_units'   => max(0, (int) ceil($daily * 30) - $product->stock),
                    'insight'         => match($urgency) {
                        'critical' => "Only {$daysRemaining} day" . ($daysRemaining === 1 ? '' : 's') . " of stock left. "
                                      . "You could lose " . number_format($revenueAtRisk, 3) . " TND.",
                        'high'     => "You have {$daysRemaining} days of stock left. Order a restock this week.",
                        default    => "Stock is getting low — plan a restock soon.",
                    },
                    'smart_actions'   => [
                        ['label' => 'Restock Now', 'href' => "/seller/products/{$product->id}", 'type' => 'restock'],
                    ],
                    'image_url'       => $product->image_path
                        ? url(\Storage::url($product->image_path))
                        : null,
                ];
            }
        }

        usort($inventoryAlerts, fn($a, $b) => $a['days_remaining'] <=> $b['days_remaining']);
        $inventoryAlerts = array_slice($inventoryAlerts, 0, 10);

        // ── Groq Market Insights — PHASE 3 IMPROVED PROMPT ───────────────
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
            $trendCount     = count($trendingProducts);
            $criticalCount  = count(array_filter($inventoryAlerts, fn($a) => $a['urgency'] === 'critical'));
            $trendingNames  = collect($trendingProducts)->pluck('product_name')->take(3)->implode(', ') ?: 'none';
            $alertNames     = collect($inventoryAlerts)->pluck('product_name')->take(3)->implode(', ') ?: 'none';
            $totalRevenue7d = round(collect($trendingProducts)->sum('seven_day_revenue'), 3);

            // ── PHASE 3: Improved prompt — plain language, warm tone ──────
            $system = "You are a trusted business advisor for a Tunisian e-commerce seller on ChooseTounsi.
Write in plain, warm, encouraging English. Max 3 bullet points.
NEVER use technical terms like 'velocity', 'conversion rate', or 'KPI'.
ALWAYS respond with ONLY valid JSON — no markdown, no preamble.";

            $userPrompt = <<<PROMPT
A seller's weekly performance on ChooseTounsi (Tunisian marketplace):

MOMENTUM: {$trendCount} product(s) selling faster than usual this week
TOP PRODUCTS: {$trendingNames}
STOCK ALERTS: {$alertNames} running low
REVENUE FROM FAST SELLERS: {$totalRevenue7d} TND this week
CRITICAL STOCK: {$criticalCount} product(s) may run out within 3 days

Write like a trusted friend who knows their business. Be specific, warm, and actionable.

Respond ONLY with:
{
  "headline": "<one sentence that makes the seller feel informed and motivated>",
  "insights": [
    "<what is going well, in plain language>",
    "<what needs attention today, specific and actionable>",
    "<one opportunity they can act on right now>"
  ],
  "priority_action": "<the single most important thing to do right now — be specific, mention the product name if relevant>",
  "market_temperature": "hot"|"warm"|"cooling"|"cold"
}
PROMPT;

            $raw = $this->callGroq($system, $userPrompt, 450);
            if ($raw) {
                $parsed = $this->parseGroqJson($raw);
                if ($parsed) {
                    $insights = $parsed;
                    @mkdir(storage_path('app/cache'), 0755, true);
                    file_put_contents($cachePath, json_encode([
                        'content'    => $insights,
                        'expires_at' => now()->addHours(6)->toISOString(),
                    ]));
                }
            }

            if (!$insights) {
                $insights = [
                    'headline'           => $trendCount > 0
                        ? "{$trendCount} of your products are gaining momentum this week."
                        : 'Your store performance data is ready for analysis.',
                    'insights'           => [
                        $trendCount > 0
                            ? "Your trending products are performing well — consider sponsoring them for even more reach."
                            : 'Drive traffic to your top products using the Visibility Control panel.',
                        count($inventoryAlerts) > 0
                            ? count($inventoryAlerts) . ' product(s) need restocking within 14 days to avoid losing sales.'
                            : 'Your inventory levels are healthy across all active products.',
                        'Sponsored products on ChooseTounsi see an average 35% increase in visibility.',
                    ],
                    'priority_action'    => count($inventoryAlerts) > 0
                        ? 'Restock ' . ($inventoryAlerts[0]['product_name'] ?? 'your at-risk products') . ' immediately.'
                        : 'Activate sponsorship on your fastest-moving product.',
                    'market_temperature' => $trendCount >= 3 ? 'hot' : ($trendCount >= 1 ? 'warm' : 'cooling'),
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
                    'trending_count' => count($trendingProducts),
                    'alert_count'    => count($inventoryAlerts),
                    'critical_count' => count(array_filter($inventoryAlerts, fn($a) => $a['urgency'] === 'critical')),
                    'generated_at'   => now()->toISOString(),
                ],
            ],
        ]);
    }

    // =========================================================================
    // 2. DAILY BRIEF
    //    GET /api/seller/black/daily-brief
    // =========================================================================
    public function dailyBrief(Request $request): JsonResponse
    {
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();
        $now       = Carbon::now();
        $seller    = auth()->user();

        $cacheKey  = "black_daily_brief_{$sellerId}";
        $cachePath = storage_path("app/cache/{$cacheKey}.json");

        if (file_exists($cachePath)) {
            $cached = json_decode(file_get_contents($cachePath), true);
            if ($cached && isset($cached['expires_at']) && Carbon::parse($cached['expires_at'])->isFuture()) {
                return response()->json(['success' => true, 'data' => $cached['content']]);
            }
        }

        // Revenue delta
        $todayRevenue = (float) DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->whereDate('o.created_at', $now->toDateString())
            ->sum(DB::raw($totalExpr));

        $yesterdayRevenue = (float) DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->whereDate('o.created_at', $now->copy()->subDay()->toDateString())
            ->sum(DB::raw($totalExpr));

        $revenuePositive = $todayRevenue >= $yesterdayRevenue;
        if ($yesterdayRevenue > 0) {
            $delta        = round((($todayRevenue - $yesterdayRevenue) / $yesterdayRevenue) * 100, 1);
            $revenueDelta = ($delta >= 0 ? '+' : '') . $delta . '% vs yesterday';
        } elseif ($todayRevenue > 0) {
            $revenueDelta = 'First sales today!'; $revenuePositive = true;
        } else {
            $revenueDelta = 'No sales yet today'; $revenuePositive = false;
        }

        // Trending count
        $trendingCount = 0;
        try {
            $thirtyDayAvg = DB::table('order_items as oi')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where("p.{$sellerCol}", $sellerId)->whereNull('p.deleted_at')
                ->whereIn('o.status', ['completed', 'delivered'])
                ->where('o.created_at', '>=', $now->copy()->subDays(30))
                ->selectRaw("oi.product_id, SUM(oi.quantity) / 30.0 as daily_avg")
                ->groupBy('oi.product_id')->get()->keyBy('product_id');

            $sevenDaySales = DB::table('order_items as oi')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where("p.{$sellerCol}", $sellerId)->whereNull('p.deleted_at')
                ->whereIn('o.status', ['completed', 'delivered'])
                ->where('o.created_at', '>=', $now->copy()->subDays(7))
                ->selectRaw("oi.product_id, SUM(oi.quantity) as seven_day_total")
                ->groupBy('oi.product_id')->get();

            foreach ($sevenDaySales as $row) {
                $avg = $thirtyDayAvg[$row->product_id]->daily_avg ?? 0;
                $vel = $row->seven_day_total / 7.0;
                if ($avg > 0 && $vel >= $avg * 1.5) $trendingCount++;
            }
        } catch (\Throwable $e) {
            Log::warning('[DailyBrief] Trending count failed: ' . $e->getMessage());
        }

        // Risk count
        $riskCount = 0;
        try {
            $avgDailySales = DB::table('order_items as oi')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where("p.{$sellerCol}", $sellerId)->whereNull('p.deleted_at')
                ->whereIn('o.status', ['completed', 'delivered'])
                ->where('o.created_at', '>=', $now->copy()->subDays(30))
                ->selectRaw("oi.product_id, SUM(oi.quantity) / 30.0 as daily_avg")
                ->groupBy('oi.product_id')->get()->keyBy('product_id');

            $products = DB::table('products')
                ->where($sellerCol, $sellerId)->whereNull('deleted_at')
                ->where('is_approved', true)->where('stock', '>', 0)
                ->select('id', 'stock')->get();

            foreach ($products as $product) {
                $daily = $avgDailySales[$product->id]->daily_avg ?? 0;
                if ($daily > 0 && ($product->stock / $daily) <= 7) $riskCount++;
            }
        } catch (\Throwable $e) {
            Log::warning('[DailyBrief] Risk count failed: ' . $e->getMessage());
        }

        // Top priority action
        $topAction = null;
        if ($riskCount > 0) {
            try {
                $avgDailySales2 = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->where('o.created_at', '>=', $now->copy()->subDays(30))
                    ->selectRaw("oi.product_id, SUM(oi.quantity) / 30.0 as daily_avg")
                    ->groupBy('oi.product_id')->get()->keyBy('product_id');

                $urgentProduct = DB::table('products')
                    ->where($sellerCol, $sellerId)->whereNull('deleted_at')
                    ->where('is_approved', true)->where('stock', '>', 0)
                    ->select('id', 'name', 'stock')->get()
                    ->filter(function ($p) use ($avgDailySales2) {
                        $daily = $avgDailySales2[$p->id]->daily_avg ?? 0;
                        return $daily > 0 && ($p->stock / $daily) <= 7;
                    })
                    ->sortBy(function ($p) use ($avgDailySales2) {
                        $daily = $avgDailySales2[$p->id]->daily_avg ?? 0;
                        return $daily > 0 ? ($p->stock / $daily) : PHP_INT_MAX;
                    })->first();

                if ($urgentProduct) {
                    $topAction = [
                        'label' => "Restock {$urgentProduct->name} before it runs out",
                        'href'  => "/seller/products/{$urgentProduct->id}",
                        'type'  => 'restock',
                    ];
                }
            } catch (\Throwable $e) {}
        } elseif ($trendingCount > 0) {
            $topAction = ['label' => 'Promote your trending products for maximum reach', 'href' => '/seller/promote', 'type' => 'promote'];
        } else {
            $topAction = ['label' => 'Add a flash sale to boost slow-moving products', 'href' => '/seller/promotions', 'type' => 'flash_sale'];
        }

        // Greeting
        $hour         = (int) $now->format('H');
        $timeGreeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $firstName    = explode(' ', $seller->name ?? 'Seller')[0];
        $greeting     = "{$timeGreeting}, {$firstName}!";

        // ── PHASE 3: Improved Groq prompt for ai_message ─────────────────
        $aiMessage = null;
        $system = "You are a warm, encouraging business coach for a Tunisian online seller. "
            . "Write exactly ONE sentence in plain English. "
            . "Max 22 words. No emojis. No jargon. "
            . "Sound like a trusted friend, not a corporate tool. "
            . "Make the seller feel capable and motivated.";

        $userMsg = "Seller's situation right now on ChooseTounsi:\n"
            . "- Revenue today vs yesterday: {$revenueDelta}\n"
            . "- Products selling faster than usual: {$trendingCount}\n"
            . "- Products running low on stock: {$riskCount}\n\n"
            . "Write one warm, specific, encouraging sentence. "
            . "If stock is at risk, mention restocking. "
            . "If trending, mention momentum. "
            . "If slow day, suggest a positive action.";

        $raw = $this->callGroq($system, $userMsg, 60);
        if ($raw) {
            $aiMessage = trim(trim($raw), '"\'');
        }

        if (!$aiMessage) {
            if ($trendingCount > 0 && $riskCount === 0) {
                $aiMessage = "Your store is doing well — keep the momentum going.";
            } elseif ($riskCount > 0) {
                $aiMessage = "Restock your at-risk products today to protect your revenue.";
            } else {
                $aiMessage = "A great day to try a flash sale or new promotion.";
            }
        }

        $brief = [
            'greeting'         => $greeting,
            'revenue_delta'    => $revenueDelta,
            'revenue_positive' => $revenuePositive,
            'trending_count'   => $trendingCount,
            'risk_count'       => $riskCount,
            'ai_message'       => $aiMessage,
            'top_action'       => $topAction,
        ];

        @mkdir(storage_path('app/cache'), 0755, true);
        file_put_contents($cachePath, json_encode([
            'content'    => $brief,
            'expires_at' => now()->addHours(4)->toISOString(),
        ]));

        return response()->json(['success' => true, 'data' => $brief]);
    }

    // =========================================================================
    // 3. FUNNEL INSIGHTS
    //    GET /api/seller/black/funnel-insights
    // =========================================================================
    public function funnelInsights(Request $request): JsonResponse
    {
        $sellerId = auth()->id();
        $data     = Cache::remember("black_funnel_insights_{$sellerId}", now()->addHours(6), function () use ($sellerId) {
            return (new FunnelInsightService())->analyze($sellerId);
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => ['count' => count($data), 'generated_at' => now()->toISOString()],
        ]);
    }

    // =========================================================================
    // 4. QUALITY AUDIT
    //    GET /api/seller/black/quality-audit
    // =========================================================================
    public function qualityAudit(Request $request): JsonResponse
    {
        $sellerId  = auth()->id();
        $data      = Cache::remember("black_quality_audit_{$sellerId}", now()->addHours(2), function () use ($sellerId) {
            return (new ProductQualityService())->analyzeAll($sellerId);
        });

        $avgScore  = count($data) > 0 ? round(array_sum(array_column($data, 'score')) / count($data)) : 0;
        $needsWork = count(array_filter($data, fn($p) => $p['score'] < 60));

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => ['product_count' => count($data), 'avg_score' => $avgScore, 'needs_work' => $needsWork, 'generated_at' => now()->toISOString()],
        ]);
    }

    // =========================================================================
    // 5. AUTO-PROMOTE SUGGESTIONS  ← PHASE 3 NEW
    //    GET /api/seller/black/auto-promote-suggestions
    // =========================================================================
    public function autoPromote(Request $request): JsonResponse
    {
        $sellerId = auth()->id();
        $data     = Cache::remember("black_auto_promote_{$sellerId}", now()->addHours(3), function () use ($sellerId) {
            return (new AutoPromotionService())->suggest($sellerId);
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'count'        => count($data),
                'unspon_count' => count(array_filter($data, fn($p) => !$p['already_sponsored'])),
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    // =========================================================================
    // CACHE INVALIDATION — call from SellerProductController after store/update
    // =========================================================================
    public static function clearSellerCache(int $sellerId): void
    {
        Cache::forget("black_quality_audit_{$sellerId}");
        Cache::forget("black_funnel_insights_{$sellerId}");
        Cache::forget("black_daily_brief_{$sellerId}");
        Cache::forget("black_ai_hub_insights_{$sellerId}");
        Cache::forget("black_auto_promote_{$sellerId}");  // ← Phase 3 added
    }

    // =========================================================================
    // 6. PROFIT COMMAND CENTER
    //    GET /api/seller/black/profit-center
    // =========================================================================
    public function profitCenter(Request $request): JsonResponse
    {
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();
        $now       = Carbon::now();

        $dailyRevenue = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subDays(90))
            ->selectRaw("DATE(o.created_at) as day, SUM({$totalExpr}) as revenue, COUNT(DISTINCT oi.order_id) as orders")
            ->groupBy('day')->orderBy('day')->get();

        $monthlyRevenue = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subMonths(6))
            ->selectRaw("DATE_FORMAT(o.created_at, '%Y-%m') as month, SUM({$totalExpr}) as revenue, SUM(oi.quantity) as units, COUNT(DISTINCT oi.order_id) as orders")
            ->groupBy('month')->orderBy('month')->get();

        $productMargins = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where("p.{$sellerCol}", $sellerId)->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', $now->copy()->subMonths(3))
            ->selectRaw("
                p.id as product_id, p.name as product_name, c.name as category_name, p.price,
                SUM(oi.quantity) as total_units, SUM({$totalExpr}) as total_revenue,
                SUM({$totalExpr}) - SUM(oi.quantity * p.price * 0.60) as estimated_profit,
                (SUM({$totalExpr}) - SUM(oi.quantity * p.price * 0.60)) / NULLIF(SUM({$totalExpr}), 0) * 100 as margin_pct
            ")
            ->groupBy('p.id', 'p.name', 'c.name', 'p.price')
            ->orderByDesc('total_revenue')->limit(20)->get()
            ->map(function ($row) {
                $marginLabel = (float) $row->margin_pct >= 35 ? 'excellent'
                    : ((float) $row->margin_pct >= 25 ? 'good'
                    : ((float) $row->margin_pct >= 15 ? 'fair' : 'low'));
                return [
                    'product_id'       => (int) $row->product_id,
                    'product_name'     => $row->product_name,
                    'category'         => $row->category_name,
                    'price'            => (float) $row->price,
                    'total_units'      => (int) $row->total_units,
                    'total_revenue'    => round((float) $row->total_revenue, 3),
                    'estimated_profit' => round((float) $row->estimated_profit, 3),
                    'margin_pct'       => round((float) $row->margin_pct, 1),
                    'margin_label'     => $marginLabel,
                    'margin_human'     => match($marginLabel) {
                        'excellent' => 'Great profit margin',
                        'good'      => 'Good profit margin',
                        'fair'      => 'Average profit margin',
                        'low'       => 'Low profit product',
                        default     => $marginLabel,
                    },
                ];
            });

        $n        = $dailyRevenue->count();
        $forecast = null;

        if ($n >= 7) {
            $xMean = ($n - 1) / 2.0;
            $yMean = $dailyRevenue->avg('revenue');
            $numerator = $denominator = 0.0;

            foreach ($dailyRevenue as $i => $row) {
                $x = $i - $xMean; $y = (float)$row->revenue - $yMean;
                $numerator += $x * $y; $denominator += $x * $x;
            }

            $slope = $denominator > 0 ? $numerator / $denominator : 0;
            $intercept = $yMean - $slope * $xMean;
            $forecastDays = [];

            for ($d = 1; $d <= 30; $d++) {
                $forecastDays[] = [
                    'day'       => $now->copy()->addDays($d)->format('Y-m-d'),
                    'predicted' => round(max(0, $intercept + $slope * ($n - 1 + $d)), 3),
                ];
            }

            $totalForecast30 = round(collect($forecastDays)->sum('predicted'), 3);
            $last30Actual    = round($dailyRevenue->take(-30)->sum('revenue'), 3);
            $growthPct       = $last30Actual > 0 ? round(($totalForecast30 - $last30Actual) / $last30Actual * 100, 1) : 0;
            $confidence      = $n >= 30 ? 'high' : ($n >= 14 ? 'medium' : 'low');

            $forecast = [
                'next_30_days'     => $totalForecast30,
                'last_30_actual'   => $last30Actual,
                'growth_pct'       => $growthPct,
                'trend'            => $slope > 0.5 ? 'up' : ($slope < -0.5 ? 'down' : 'stable'),
                'daily_points'     => $forecastDays,
                'confidence'       => $confidence,
                'confidence_human' => match($confidence) {
                    'high'   => 'Very reliable forecast',
                    'medium' => 'Fairly confident forecast',
                    'low'    => 'Rough estimate — more data needed',
                    default  => $confidence,
                },
            ];
        }

        $totalRevenue90d = round($dailyRevenue->sum('revenue'), 3);
        $periodBreakdown = $monthlyRevenue->map(fn($row) => [
            'month'            => $row->month,
            'revenue'          => round((float)$row->revenue, 3),
            'estimated_profit' => round((float)$row->revenue * 0.40, 3),
            'units'            => (int)$row->units,
            'orders'           => (int)$row->orders,
            'avg_order_value'  => $row->orders > 0 ? round((float)$row->revenue / $row->orders, 3) : 0,
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'summary' => [
                    'total_revenue_90d'    => $totalRevenue90d,
                    'estimated_profit_90d' => round($totalRevenue90d * 0.40, 3),
                    'estimated_margin_pct' => 40.0,
                    'margin_disclaimer'    => 'Estimated margin assumes 60% cost ratio. Update when cost_price data becomes available.',
                    'data_points'          => $n,
                ],
                'period_breakdown' => $periodBreakdown,
                'product_margins'  => $productMargins,
                'forecast'         => $forecast,
                'daily_revenue'    => $dailyRevenue->map(fn($r) => [
                    'day'     => $r->day,
                    'revenue' => round((float)$r->revenue, 3),
                    'orders'  => (int)$r->orders,
                ]),
            ],
        ]);
    }

    // =========================================================================
    // 7. TOGGLE SPONSORSHIP
    //    POST /api/seller/black/sponsor/{id}
    // =========================================================================
    public function toggleSponsorship(Request $request, int $productId): JsonResponse
    {
        $request->validate([
            'action'   => 'required|in:activate,deactivate',
            'priority' => 'nullable|integer|min:1|max:10',
        ]);

        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $product   = Product::where('id', $productId)->where($sellerCol, $sellerId)->whereNull('deleted_at')->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        if ($request->action === 'activate') {
            $product->update([
                'is_sponsored'       => true,
                'sponsored_priority' => $request->input('priority', 5),
                'sponsored_at'       => now(),
                'sponsored_until'    => null,
            ]);
            try {
                $seller = auth()->user();
                $admins = User::where('role', 'admin')->get();
                foreach ($admins as $admin) {
                    $admin->notify(new SponsoredProductActivatedNotification($seller, $product));
                }
            } catch (\Throwable $e) {
                Log::warning('[BlackPepper] Sponsorship notification failed: ' . $e->getMessage());
            }
            // Clear auto-promote cache so the card updates immediately
            Cache::forget("black_auto_promote_{$sellerId}");

            return response()->json([
                'success' => true,
                'message' => "Sponsorship activated for \"{$product->name}\".",
                'data'    => ['is_sponsored' => true, 'sponsored_priority' => $product->sponsored_priority],
            ]);
        }

        $product->update(['is_sponsored' => false, 'sponsored_priority' => 0, 'sponsored_at' => null, 'sponsored_until' => null]);
        Cache::forget("black_auto_promote_{$sellerId}");

        return response()->json([
            'success' => true,
            'message' => "Sponsorship deactivated for \"{$product->name}\".",
            'data'    => ['is_sponsored' => false],
        ]);
    }

    // =========================================================================
    // 8. LIST SPONSORED PRODUCTS
    //    GET /api/seller/black/sponsored
    // =========================================================================
    public function sponsoredProducts(Request $request): JsonResponse
    {
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();

        $sponsored = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('product_images as pi', function ($join) {
                $join->on('pi.product_id', '=', 'p.id')->where('pi.is_primary', true);
            })
            ->where("p.{$sellerCol}", $sellerId)->whereNull('p.deleted_at')
            ->selectRaw("p.id, p.name, p.price, p.stock, p.is_sponsored, p.sponsored_priority,
                         p.sponsored_at, p.is_active, p.is_approved, c.name as category_name,
                         MIN(pi.image_path) as image_path")
            ->groupBy('p.id', 'p.name', 'p.price', 'p.stock', 'p.is_sponsored',
                      'p.sponsored_priority', 'p.sponsored_at', 'p.is_active', 'p.is_approved', 'c.name')
            ->orderByDesc('p.is_sponsored')->orderByDesc('p.sponsored_priority')->get()
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
                'image_url'          => $row->image_path ? url(\Storage::url($row->image_path)) : null,
            ]);

        return response()->json(['success' => true, 'data' => $sponsored]);
    }

    // =========================================================================
    // 9. SUBMIT VIP REQUEST
    //    POST /api/seller/black/vip-request
    // =========================================================================
    public function submitVipRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'    => 'required|in:reel,promotion,support',
            'message' => 'required|string|min:10|max:1000',
        ]);

        $seller       = auth()->user();
        $pendingCount = VipRequest::where('user_id', $seller->id)
            ->where('type', $validated['type'])->where('status', 'pending')->count();

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

    // =========================================================================
    // 10. LIST MY VIP REQUESTS
    //     GET /api/seller/black/vip-requests
    // =========================================================================
    public function myVipRequests(Request $request): JsonResponse
    {
        $requests = VipRequest::where('user_id', auth()->id())
            ->orderByDesc('created_at')->limit(20)->get()
            ->map(fn($r) => [
                'id'           => $r->id,
                'type'         => $r->type,
                'type_label'   => $r->type_label,
                'status'       => $r->status,
                'status_label' => $r->status_label,
                'message'      => $r->message,
                'admin_note'   => $r->admin_note,
                'created_at'   => $r->created_at->toISOString(),
                'handled_at'   => $r->handled_at?->toISOString(),
            ]);

        return response()->json(['success' => true, 'data' => $requests]);
    }
}