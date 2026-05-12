<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\SellerApplication;
use App\Services\AutoPromotionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;

/**
 * BlackDailyNotify  -- Phase 3
 *
 * Artisan command: php artisan black:daily-notify
 *
 * Runs daily at 08:00 (registered in Kernel.php).
 * Analyzes each Black Pepper seller and sends smart notifications:
 *
 *   1. AUTO_PROMO   — trending product not yet sponsored
 *   2. STOCK_RISK   — product running out within 7 days
 *   3. COOLING      — product was trending last week, now slowing
 *   4. WEEKEND_SPIKE — if today is Thursday/Friday, warn of weekend demand
 *   5. LOW_QUALITY   — seller has products scoring < 40 with no recent edit
 *
 * Each notification type fires AT MOST ONCE per seller per day.
 * Uses the existing database notification channel (NotificationBell.tsx reads it).
 *
 * Register in Kernel.php:
 *   $schedule->command('black:daily-notify')->dailyAt('08:00');
 */
class BlackDailyNotify extends Command
{
    protected $signature   = 'black:daily-notify';
    protected $description = 'Send daily smart notifications to Black Pepper sellers';

    public function handle(): int
    {
        $this->info('[BlackDailyNotify] Starting...');

        // ── Get all active Black Pepper sellers ───────────────────────────
        $blackSellerIds = SellerApplication::where('status', 'approved')
            ->where('plan', 'black')
            ->pluck('user_id')
            ->toArray();

        if (empty($blackSellerIds)) {
            $this->info('[BlackDailyNotify] No Black Pepper sellers found. Exiting.');
            return 0;
        }

        $sellers = User::whereIn('id', $blackSellerIds)->get()->keyBy('id');
        $this->info('[BlackDailyNotify] Processing ' . count($blackSellerIds) . ' sellers.');

        $sent = 0;

        foreach ($blackSellerIds as $sellerId) {
            $seller = $sellers[$sellerId] ?? null;
            if (!$seller) continue;

            try {
                $notified = $this->processseller($seller);
                $sent += $notified;
                $this->line("  Seller #{$sellerId}: {$notified} notification(s) sent.");
            } catch (\Throwable $e) {
                Log::error("[BlackDailyNotify] Failed for seller #{$sellerId}: " . $e->getMessage());
                $this->error("  Seller #{$sellerId}: ERROR — " . $e->getMessage());
            }
        }

        $this->info("[BlackDailyNotify] Done. Total notifications sent: {$sent}");
        return 0;
    }

    /**
     * Analyze one seller and send relevant notifications.
     * Returns the number of notifications sent.
     */
    private function processSeller(User $seller): int
    {
        $sellerId  = $seller->id;
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();
        $now       = Carbon::now();
        $sent      = 0;
        $dayKey    = $now->format('Y-m-d');

        // ── Dedup: skip notification types already sent today ─────────────
        $sentToday = DB::table('notifications')
            ->where('notifiable_id', $sellerId)
            ->where('notifiable_type', 'App\\Models\\User')
            ->whereDate('created_at', $now->toDateString())
            ->whereRaw("JSON_EXTRACT(data, '$.source') = 'black_daily_notify'")
            ->pluck(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.notify_type'))"))
            ->toArray();

        // ─────────────────────────────────────────────────────────────────
        // NOTIFICATION 1: Auto-Promote Suggestion
        // ─────────────────────────────────────────────────────────────────
        if (!in_array('auto_promo', $sentToday)) {
            try {
                $suggestions = (new AutoPromotionService())->suggest($sellerId);
                $unspon      = array_filter($suggestions, fn($s) => !$s['already_sponsored']);

                if (!empty($unspon)) {
                    $top = array_values($unspon)[0];
                    $seller->notify(new \App\Notifications\BlackSmartNotification([
                        'notify_type' => 'auto_promo',
                        'source'      => 'black_daily_notify',
                        'title'       => 'Your hottest product is not sponsored yet',
                        'body'        => $top['product_name'] . ' is selling fast. Sponsoring it could add '
                                        . $top['estimated_boost_tnd'] . ' TND this week.',
                        'icon'        => 'zap',
                        'action'      => 'promote',
                        'link'        => '/seller/black',
                        'product_id'  => $top['product_id'],
                    ]));
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning("[BlackDailyNotify] auto_promo failed for {$sellerId}: " . $e->getMessage());
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // NOTIFICATION 2: Stock Risk
        // ─────────────────────────────────────────────────────────────────
        if (!in_array('stock_risk', $sentToday)) {
            try {
                $avgSales = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->where('o.created_at', '>=', $now->copy()->subDays(30))
                    ->selectRaw("oi.product_id, SUM(oi.quantity) / 30.0 as daily_avg")
                    ->groupBy('oi.product_id')
                    ->get()->keyBy('product_id');

                $atRisk = DB::table('products')
                    ->where($sellerCol, $sellerId)->whereNull('deleted_at')
                    ->where('is_approved', true)->where('stock', '>', 0)
                    ->select('id', 'name', 'stock')->get()
                    ->filter(function ($p) use ($avgSales) {
                        $daily = $avgSales[$p->id]->daily_avg ?? 0;
                        return $daily > 0 && ($p->stock / $daily) <= 4;
                    })->first();

                if ($atRisk) {
                    $daily = $avgSales[$atRisk->id]->daily_avg ?? 1;
                    $days  = (int) floor($atRisk->stock / $daily);
                    $seller->notify(new \App\Notifications\BlackSmartNotification([
                        'notify_type' => 'stock_risk',
                        'source'      => 'black_daily_notify',
                        'title'       => $atRisk->name . ' runs out in ' . $days . ' day' . ($days === 1 ? '' : 's'),
                        'body'        => 'Only ' . $atRisk->stock . ' units left. Restock today to avoid losing sales this week.',
                        'icon'        => 'alert-triangle',
                        'action'      => 'restock',
                        'link'        => '/seller/products/' . $atRisk->id,
                        'product_id'  => $atRisk->id,
                    ]));
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning("[BlackDailyNotify] stock_risk failed for {$sellerId}: " . $e->getMessage());
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // NOTIFICATION 3: Weekend Demand Spike (Thursday/Friday only)
        // ─────────────────────────────────────────────────────────────────
        if (!in_array('weekend_spike', $sentToday) && in_array($now->dayOfWeek, [4, 5])) {
            try {
                // Find top selling product last weekend
                $topProduct = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->whereBetween('o.created_at', [
                        $now->copy()->subWeek()->startOfWeekend(),
                        $now->copy()->subWeek()->endOfWeekend(),
                    ])
                    ->selectRaw("oi.product_id, p.name, SUM(oi.quantity) as units")
                    ->groupBy('oi.product_id', 'p.name')
                    ->orderByDesc('units')
                    ->first();

                if ($topProduct) {
                    $seller->notify(new \App\Notifications\BlackSmartNotification([
                        'notify_type' => 'weekend_spike',
                        'source'      => 'black_daily_notify',
                        'title'       => 'Weekend demand spike coming',
                        'body'        => 'Last weekend, ' . $topProduct->name . ' was your best seller. '
                                        . 'Make sure it is stocked and sponsored before Friday evening.',
                        'icon'        => 'trending-up',
                        'action'      => 'promote',
                        'link'        => '/seller/black',
                        'product_id'  => $topProduct->product_id,
                    ]));
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning("[BlackDailyNotify] weekend_spike failed for {$sellerId}: " . $e->getMessage());
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // NOTIFICATION 4: Cooling Product
        // Was trending last week, now slowing down
        // ─────────────────────────────────────────────────────────────────
        if (!in_array('cooling', $sentToday)) {
            try {
                // Last week avg vs this week avg
                $lastWeekSales = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->whereBetween('o.created_at', [
                        $now->copy()->subDays(14),
                        $now->copy()->subDays(7),
                    ])
                    ->selectRaw("oi.product_id, SUM(oi.quantity) as units")
                    ->groupBy('oi.product_id')
                    ->get()->keyBy('product_id');

                $thisWeekSales = DB::table('order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where("p.{$sellerCol}", $sellerId)
                    ->whereNull('p.deleted_at')
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->where('o.created_at', '>=', $now->copy()->subDays(7))
                    ->selectRaw("oi.product_id, p.name, SUM(oi.quantity) as units")
                    ->groupBy('oi.product_id', 'p.name')
                    ->get()->keyBy('product_id');

                // Find a product that was strong last week but dropped > 40% this week
                $coolingProduct = null;
                foreach ($lastWeekSales as $productId => $lastWeek) {
                    $thisWeek = $thisWeekSales[$productId]->units ?? 0;
                    if ($lastWeek->units >= 5 && $thisWeek < $lastWeek->units * 0.6) {
                        $name = $thisWeekSales[$productId]->name ?? null;
                        if ($name) {
                            $coolingProduct = [
                                'id'         => $productId,
                                'name'       => $name,
                                'last_units' => $lastWeek->units,
                                'this_units' => $thisWeek,
                            ];
                            break;
                        }
                    }
                }

                if ($coolingProduct) {
                    $seller->notify(new \App\Notifications\BlackSmartNotification([
                        'notify_type' => 'cooling',
                        'source'      => 'black_daily_notify',
                        'title'       => $coolingProduct['name'] . ' is slowing down',
                        'body'        => 'This product sold ' . $coolingProduct['last_units'] . ' units last week '
                                        . 'but only ' . $coolingProduct['this_units'] . ' so far this week. '
                                        . 'A flash sale or price adjustment could bring buyers back.',
                        'icon'        => 'trending-down',
                        'action'      => 'flash_sale',
                        'link'        => '/seller/promotions',
                        'product_id'  => $coolingProduct['id'],
                    ]));
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning("[BlackDailyNotify] cooling failed for {$sellerId}: " . $e->getMessage());
            }
        }

        return $sent;
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
        if (in_array('total', $cols))                                           $parts[] = 'oi.total';
        if (in_array('unit_price', $cols) && in_array('quantity', $cols))      $parts[] = 'oi.unit_price * oi.quantity';
        elseif (in_array('price', $cols) && in_array('quantity', $cols))       $parts[] = 'oi.price * oi.quantity';
        $parts[] = '0';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }
}