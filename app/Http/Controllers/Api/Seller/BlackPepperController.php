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
use App\Services\ProductQualityService;
use Illuminate\Support\Facades\Cache;
/**
 * BlackPepperController  — Phases 1, 2 & 3 complete
 *
 * Endpoints:
 *   GET  /api/seller/black/ai-hub
 *   GET  /api/seller/black/daily-brief
 *   (profit center → ProfitCenterController)
 *   GET  /api/seller/black/visitor-insights   (VisitorInsightsController)
 *   GET  /api/seller/black/quality-audit
 *   GET  /api/seller/black/auto-promote-suggestions   ← Phase 3
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

    /** Language name for LLM prompts — replies follow the seller's interface language. */
    private function replyLanguage(): string
    {
        return match (app()->getLocale()) {
            'ar'    => 'Arabic (Modern Standard Arabic, Tunisian-friendly)',
            'en'    => 'English',
            default => 'French',
        };
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

        $cacheKey  = "black_daily_brief_{$sellerId}_" . app()->getLocale();
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
            $revenueDelta = __('seller.black.brief.vs_yesterday', ['pct' => ($delta >= 0 ? '+' : '') . $delta]);
        } elseif ($todayRevenue > 0) {
            $revenueDelta = __('seller.black.brief.first_sales'); $revenuePositive = true;
        } else {
            $revenueDelta = __('seller.black.brief.no_sales'); $revenuePositive = false;
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
                        'label' => __('seller.black.brief.restock', ['name' => $urgentProduct->name]),
                        'href'  => "/seller/products/{$urgentProduct->id}",
                        'type'  => 'restock',
                    ];
                }
            } catch (\Throwable $e) {}
        } elseif ($trendingCount > 0) {
            $topAction = ['label' => __('seller.black.brief.promote_trending'), 'href' => '/seller/promote', 'type' => 'promote'];
        } else {
            $topAction = ['label' => __('seller.black.brief.flash_sale'), 'href' => '/seller/promotions', 'type' => 'flash_sale'];
        }

        // Greeting
        $hour         = (int) $now->format('H');
        $firstName    = explode(' ', $seller->name ?? '')[0] ?: __('seller.black.brief.seller');
        $greeting     = __($hour < 12 ? 'seller.black.brief.morning' : ($hour < 17 ? 'seller.black.brief.afternoon' : 'seller.black.brief.evening'), ['name' => $firstName]);

        // ── PHASE 3: Improved Groq prompt for ai_message ─────────────────
        $aiMessage = null;
        $system = "You are a warm, encouraging business coach for a Tunisian online seller. "
            . "Write exactly ONE sentence in plain {$this->replyLanguage()}. "
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
                $aiMessage = __('seller.black.brief.ai_good');
            } elseif ($riskCount > 0) {
                $aiMessage = __('seller.black.brief.ai_restock');
            } else {
                $aiMessage = __('seller.black.brief.ai_flash');
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
    // 3. FUNNEL INSIGHTS → moved to VisitorInsightsController (GET /seller/black/visitor-insights)
    // 4. QUALITY AUDIT
    //    GET /api/seller/black/quality-audit
    // =========================================================================
    public function qualityAudit(Request $request): JsonResponse
    {
        $sellerId  = auth()->id();
        $data      = Cache::remember("black_quality_audit_{$sellerId}_" . app()->getLocale(), now()->addHours(2), function () use ($sellerId) {
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
    // CACHE INVALIDATION — call from SellerProductController after store/update
    // =========================================================================
    public static function clearSellerCache(int $sellerId): void
    {
        foreach (['fr', 'ar', 'en'] as $loc) {
            Cache::forget("black_quality_audit_{$sellerId}_{$loc}");
            Cache::forget("black_daily_brief_{$sellerId}_{$loc}");
            @unlink(storage_path("app/cache/black_daily_brief_{$sellerId}_{$loc}.json"));
        }  // ← Phase 3 added
        \App\Services\VisitorInsights\VisitorInsights::forget($sellerId);
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
                'message' => __('seller.black.vip_limit'),
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
            'message' => __('seller.black.vip_submitted'),
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