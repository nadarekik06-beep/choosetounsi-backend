<?php

namespace App\Services\Profit;

use App\Models\ProfitAlertSetting;
use App\Models\RevenueGoal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Centre de profit payload: goal progress, projection, money breakdown,
 * history, streaks, contributors, ad return, payouts and rule-based tips.
 * All money comes from SellerRevenueService; this class only does the math.
 */
class ProfitCenter
{
    public const PRESETS = ['prudent' => 0.95, 'realistic' => 1.10, 'ambitious' => 1.25];

    public function __construct(private SellerRevenueService $revenue) {}

    public function build(int $sellerId, ?CarbonImmutable $now = null): array
    {
        $now      = $now ?? $this->revenue->now();
        $month    = $now->startOfMonth();
        $next     = $month->addMonthNoOverflow();
        $ym       = $month->format('Y-m');
        $prevM    = $month->subMonthNoOverflow();
        $prevYm   = $prevM->format('Y-m');
        $dim      = $month->daysInMonth;
        $day      = (int) $now->format('j');
        $elapsed  = max(0.5, $month->diffInSeconds($now) / 86400);   // fractional days since the 1st, 00:00
        $daysLeft = $dim - $day + 1;                                     // today included

        $goals = RevenueGoal::where('seller_id', $sellerId)->orderBy('month')->get()->keyBy('month');
        $goal  = $goals[$ym] ?? null;

        // One grouped query: 12 months back (projection baseline, same month last year)
        // and every month the seller ever had a goal (streaks).
        $from = $month->subMonthsNoOverflow(12);
        if ($goals->isNotEmpty() && $goals->keys()->first() < $from->format('Y-m')) {
            $from = $this->revenue->monthStart($goals->keys()->first());
        }
        $months = $this->revenue->monthly($sellerId, $from, $next);
        $cur    = $months[$ym];
        $prev   = $months[$prevYm];

        $firstSale  = $this->revenue->firstSaleAt($sellerId);
        $suggestion = $this->suggest($months, $month, $firstSale);
        $prevSameTo = min($prevM->addSeconds((int) round($elapsed * 86400)), $month);
        $prevSame   = $this->revenue->salesBetween($sellerId, $prevM, $prevSameTo);
        $avgBasket  = $this->avgBasket($cur, $months, $month);
        $projection = $this->project($cur['sales'], $cur['orders'], $elapsed, $dim, $suggestion['reference'], $suggestion['months']);
        $awaiting   = $this->revenue->awaitingConfirmation($sellerId);
        $progress   = $this->progress($goal, $cur, $projection, $avgBasket, $elapsed, $dim, $daysLeft);
        $streak     = $this->streaks($goals, $months, $ym, $cur['sales']);
        $products   = $this->contributors($sellerId, $month, $now, $prevM, $prevSameTo, $elapsed, $cur['sales']);
        $ads        = $this->revenue->adReturn($sellerId, $month);
        $payout     = $this->revenue->payouts($sellerId, $month);

        return [
            'month'         => $ym,
            'today'         => $now->toDateString(),
            'day'           => $day,
            'days_in_month' => $dim,
            'days_left'     => $daysLeft,
            'state'         => $firstSale || $awaiting['count'] > 0 ? 'active' : 'new',
            'goal'          => $goal ? $this->goalPublic($goal) : null,
            'progress'      => $progress,
            'projection'    => $projection,
            'kpis'          => [
                'sales'            => $cur['sales'],
                'delivered'        => $cur['delivered'],
                'in_progress'      => $cur['in_progress'],
                'orders'           => $cur['orders'],
                'net'              => $cur['net'],
                'sales_prev_same'  => $prevSame['sales'],
                'orders_prev_same' => $prevSame['orders'],
                'avg_basket'       => $avgBasket,
                'awaiting'         => $awaiting,
            ],
            'breakdown'     => ['current' => $cur, 'previous' => $prev],
            'cumulative'    => $this->revenue->dailyCumulative($sellerId, $month, $now),
            'history'       => $this->history($months, $goals, $month),
            'streak'        => $streak,
            'suggestion'    => $suggestion,
            'contributors'  => $products,
            'ads'           => $ads,
            'payout'        => $payout,
            'tips'          => $this->tips($sellerId, $goal, $progress, $cur, $awaiting, $products, $ads, $suggestion),
            'alerts'        => ProfitAlertSetting::forSeller($sellerId)->toPublic(),
        ];
    }

    // ── Goal ────────────────────────────────────────────────────────────────

    public function goalPublic(RevenueGoal $g): array
    {
        return [
            'month'           => $g->month,
            'amount'          => round($g->goal_amount, 3),
            'orders_target'   => $g->orders_target,
            'net_target'      => $g->net_target !== null ? round($g->net_target, 3) : null,
            'preset'          => $g->preset,
            'milestones_sent' => array_values($g->milestones_sent ?? []),
            'updated_at'      => $g->updated_at?->toIso8601String(),
        ];
    }

    private function progress(?RevenueGoal $goal, array $cur, ?array $projection, ?float $avgBasket, float $elapsed, int $dim, int $daysLeft): array
    {
        $currentDaily = round($cur['sales'] / max(1, $elapsed), 3);
        if (!$goal || $goal->goal_amount <= 0) {
            return ['status' => 'none', 'current_daily' => $currentDaily];
        }

        $target    = $goal->goal_amount;
        $remaining = max(0, $target - $cur['sales']);
        $status    = match (true) {
            $cur['sales'] >= $target       => 'reached',
            $projection === null           => 'unknown',
            $projection['amount'] >= $target * 1.05 => 'ahead',
            $projection['amount'] >= $target * 0.97 => 'on_track',
            default                        => 'behind',
        };

        return [
            'status'          => $status,
            'pct'             => round($cur['sales'] / $target * 100, 1),
            'pct_delivered'   => round($cur['delivered'] / $target * 100, 1),
            'remaining'       => round($remaining, 3),
            'expected_by_now' => round($target * min(1, $elapsed / $dim), 3),
            'current_daily'   => $currentDaily,
            'required_daily'  => round($remaining / max(1, $daysLeft), 3),
            'orders_needed'   => $remaining > 0 && $avgBasket ? (int) ceil($remaining / $avgBasket) : 0,
            'gap_pct'         => $projection && $status === 'behind' ? round((1 - $projection['amount'] / $target) * 100) : null,
            'orders'          => $goal->orders_target ? ['done' => $cur['orders'], 'target' => $goal->orders_target,
                                    'pct' => round($cur['orders'] / $goal->orders_target * 100, 1)] : null,
            'net'             => $goal->net_target ? ['done' => $cur['net'], 'target' => round($goal->net_target, 3),
                                    'pct' => round($cur['net'] / $goal->net_target * 100, 1)] : null,
        ];
    }

    /**
     * End-of-month estimate. Run-rate (sales so far ÷ days elapsed) blended with the
     * seller's usual month while the month is young or orders are few — so the 3rd
     * of the month with one order never projects 0 or a wild number.
     */
    public function project(float $sales, int $orders, float $elapsed, int $dim, ?float $baseline, int $historyMonths): ?array
    {
        if ($sales <= 0 && !$baseline) return null;

        $fraction = min(1, $elapsed / $dim);
        $w = $baseline
            ? min(1, $fraction * 1.5) * ($orders >= 5 ? 1 : ($orders >= 2 ? 0.75 : 0.5))
            : 1.0;
        $runDaily  = $sales / $elapsed;
        $baseDaily = ($baseline ?? 0) / $dim;
        $amount    = $sales + max(0, $dim - $elapsed) * ($w * $runDaily + (1 - $w) * $baseDaily);

        $score = ($fraction >= 0.5 ? 2 : ($fraction >= 0.25 ? 1 : 0))
               + ($orders >= 10 ? 2 : ($orders >= 4 ? 1 : 0))
               + ($historyMonths >= 3 ? 1 : 0);

        return [
            'amount'     => round($amount, 3),
            // fewer than 2 orders this month: the number is mostly the usual month, say so
            'confidence' => $orders < 2 ? 'low' : ($score >= 4 ? 'high' : ($score >= 2 ? 'medium' : 'low')),
            'run_rate'   => round($runDaily * $dim, 3),
            'baseline'   => $baseline !== null ? round($baseline, 3) : null,
            'weight'     => round($w, 2),
        ];
    }

    /** Suggested goal from the last full months (weighted toward the latest), nudged by the same month last year. */
    public function suggest(array $months, CarbonImmutable $month, ?CarbonImmutable $firstSale): array
    {
        $values = [];
        if ($firstSale) {
            $firstYm = $firstSale->format('Y-m');
            for ($i = 1; $i <= 3; $i++) {
                $m = $month->subMonthsNoOverflow($i)->format('Y-m');
                if ($m < $firstYm) break;
                $values[] = $months[$m]['sales'] ?? 0.0;
            }
        }
        $weights = [[1], [0.6, 0.4], [0.5, 0.3, 0.2]][count($values) - 1] ?? [];
        $ref = 0.0;
        foreach ($values as $i => $v) $ref += $v * $weights[$i];

        $yoy = $months[$month->subYearNoOverflow()->format('Y-m')]['sales'] ?? 0.0;
        if ($ref > 0 && $yoy > 0) $ref = 0.8 * $ref + 0.2 * $yoy;

        if ($ref <= 0) {
            return ['basis' => 'starter', 'months' => count($values), 'reference' => null, 'presets' => null, 'same_month_last_year' => null];
        }

        $presets = [];
        $last = 0;
        foreach (self::PRESETS as $key => $factor) {
            $v = $this->nice($ref * $factor);
            if ($v <= $last) $v = $this->nice($last * 1.05 + 1);
            $presets[$key] = $last = $v;
        }

        return [
            'basis'                => count($values) === 1 ? 'last' : 'average',
            'months'               => count($values),
            'reference'            => round($ref, 3),
            'presets'              => $presets,
            'same_month_last_year' => $yoy > 0 ? round($yoy, 3) : null,
        ];
    }

    /** Round to a figure people actually pick: 5 / 10 / 50 / 100 DT steps. */
    private function nice(float $v): float
    {
        $step = $v < 100 ? 5 : ($v < 1000 ? 10 : ($v < 10000 ? 50 : 100));
        return (float) max($step, round($v / $step) * $step);
    }

    private function avgBasket(array $cur, array $months, CarbonImmutable $month): ?float
    {
        if ($cur['orders'] >= 3) return round($cur['sales'] / $cur['orders'], 3);
        $sales = $orders = 0;
        for ($i = 0; $i <= 3; $i++) {
            $m = $months[$month->subMonthsNoOverflow($i)->format('Y-m')] ?? null;
            if ($m) { $sales += $m['sales']; $orders += $m['orders']; }
        }
        return $orders > 0 ? round($sales / $orders, 3) : null;
    }

    // ── History & streaks ───────────────────────────────────────────────────

    private function history(array $months, Collection $goals, CarbonImmutable $month): array
    {
        $out = [];
        for ($i = 5; $i >= 0; $i--) {
            $ym = $month->subMonthsNoOverflow($i)->format('Y-m');
            $m  = $months[$ym];
            $g  = $goals[$ym] ?? null;
            $target = $g ? round($g->goal_amount, 3) : 0.0;
            $out[] = [
                'month'       => $ym,
                'current'     => $i === 0,
                'sales'       => $m['sales'],
                'delivered'   => $m['delivered'],
                'net'         => $m['net'],
                'orders'      => $m['orders'],
                'goal'        => $target,
                'hit'         => $target > 0 && $m['sales'] >= $target,
                'pct'         => $target > 0 ? round($m['sales'] / $target * 100, 1) : null,
            ];
        }
        return $out;
    }

    /**
     * Consecutive calendar months with the goal reached (a month without a goal
     * breaks the streak). The current month joins the streak once reached.
     */
    public function streaks(Collection $goals, array $months, string $ym, float $currentSales): array
    {
        $hit = fn(string $m) => isset($goals[$m]) && $goals[$m]->goal_amount > 0
            && ($m === $ym ? $currentSales : ($months[$m]['sales'] ?? 0)) >= $goals[$m]->goal_amount;

        $best = $run = 0;
        $prevKey = null;
        $hits = 0;
        $over = false;
        foreach ($goals->keys() as $m) {
            if ($m > $ym) continue;
            if ($hit($m)) {
                $hits++;
                $consecutive = $prevKey && CarbonImmutable::createFromFormat('!Y-m', $prevKey)->addMonthNoOverflow()->format('Y-m') === $m;
                $run  = $consecutive ? $run + 1 : 1;
                $best = max($best, $run);
                $prevKey = $m;
                $sales = $m === $ym ? $currentSales : ($months[$m]['sales'] ?? 0);
                if ($sales >= 1.2 * $goals[$m]->goal_amount) $over = true;
            } else {
                $run = 0;
                $prevKey = null;
            }
        }

        $current = 0;
        $cursor  = CarbonImmutable::createFromFormat('!Y-m', $ym);
        if ($hit($ym)) $current++;
        for ($c = $cursor->subMonthNoOverflow(); $hit($c->format('Y-m')); $c = $c->subMonthNoOverflow()) $current++;

        $badges = [];
        if ($hits >= 1) $badges[] = 'first_hit';
        if ($best >= 3) $badges[] = 'streak_3';
        if ($best >= 6) $badges[] = 'streak_6';
        if ($over)      $badges[] = 'overachiever';

        $bestMonth = collect($months)->filter(fn($m) => $m['sales'] > 0)->sortByDesc('sales')->first();

        return [
            'current'    => $current,
            'best'       => $best,
            'hits'       => $hits,
            'badges'     => $badges,
            'best_month' => $bestMonth ? ['month' => $bestMonth['month'], 'sales' => $bestMonth['sales']] : null,
        ];
    }

    // ── Products ────────────────────────────────────────────────────────────

    private function contributors(int $sellerId, CarbonImmutable $month, CarbonImmutable $now, CarbonImmutable $prevM,
                                  CarbonImmutable $prevSameTo, float $elapsed, float $monthSales): array
    {
        $next    = $month->addMonthNoOverflow();
        $curRef  = $this->revenue->refunds($sellerId, $month, $next)['item_ids'];
        $prevRef = $this->revenue->refunds($sellerId, $prevM, $month)['item_ids'];
        $cur     = $this->revenue->productSales($sellerId, $month, $next, $curRef);

        $top = collect($cur)->sortByDesc('revenue')->take(5)->map(fn($p) => $p + [
            'share' => $monthSales > 0 ? round($p['revenue'] / $monthSales * 100, 1) : 0,
        ])->values()->all();

        // Fair comparison: the same number of days of last month. Too noisy before day 5.
        $declining = [];
        if ($elapsed >= 5) {
            $prev = $this->revenue->productSales($sellerId, $prevM, $prevSameTo, $prevRef);
            $declining = collect($prev)
                ->reject(fn($p) => $p['deleted'])
                ->filter(fn($p) => $p['revenue'] >= 30 || $p['units'] >= 2)
                ->map(function ($p) use ($cur) {
                    $now = $cur[$p['id']]['revenue'] ?? 0.0;
                    return ['id' => $p['id'], 'name' => $p['name'], 'before' => $p['revenue'], 'now' => round($now, 3),
                            'drop_pct' => round((1 - $now / $p['revenue']) * 100), 'lost' => round($p['revenue'] - $now, 3)];
                })
                ->filter(fn($p) => $p['drop_pct'] >= 30)
                ->sortByDesc('lost')->take(3)->values()->all();
        }

        return ['top' => $top, 'declining' => $declining, 'compare_ready' => $elapsed >= 5];
    }

    // ── Tips ────────────────────────────────────────────────────────────────

    /** 2–3 short next steps, most urgent first. Texts live in the frontend (seller.profit.tips.*). */
    private function tips(int $sellerId, ?RevenueGoal $goal, array $progress, array $cur, array $awaiting,
                          array $products, ?array $ads, array $suggestion): array
    {
        $tips = [];
        $top  = $products['top'][0] ?? null;

        if ($awaiting['count'] > 0) {
            $tips[] = ['key' => 'confirm_pending', 'tone' => 'warn', 'href' => '/seller/orders?status=pending',
                       'params' => ['count' => $awaiting['count'], 'amount' => $awaiting['amount']]];
        }
        if (!$goal) {
            $tips[] = ['key' => 'set_goal', 'tone' => 'info', 'action' => 'open_goal',
                       'params' => ['amount' => $suggestion['presets']['realistic'] ?? null]];
        } elseif ($progress['status'] === 'behind') {
            $tips[] = ['key' => $top ? 'behind_flash' : 'behind', 'tone' => 'warn', 'href' => '/seller/promotions',
                       'params' => ['gap' => $progress['gap_pct'], 'product' => $top['name'] ?? null, 'orders' => $progress['orders_needed']]];
        }
        if ($cur['sales'] <= 0) {
            $active = DB::table('products')->where('seller_id', $sellerId)->whereNull('deleted_at')
                ->where('is_active', true)->where('is_approved', true)->count();
            $tips[] = $active > 0
                ? ['key' => 'no_sales_boost', 'tone' => 'info', 'href' => '/seller/promote', 'params' => []]
                : ['key' => 'no_products', 'tone' => 'info', 'href' => '/seller/products', 'params' => []];
        }
        if ($ads && $ads['roas'] !== null && $ads['roas'] < 1 && ($ads['spend'] + $ads['credit']) >= 5) {
            $tips[] = ['key' => 'ads_low_return', 'tone' => 'warn', 'href' => '/seller/promote', 'params' => ['roas' => $ads['roas']]];
        }
        if ($d = $products['declining'][0] ?? null) {
            $tips[] = ['key' => 'declining', 'tone' => 'info', 'href' => "/seller/products/{$d['id']}",
                       'params' => ['product' => $d['name'], 'drop' => $d['drop_pct']]];
        }
        if ($goal && in_array($progress['status'], ['ahead', 'reached'], true)) {
            $tips[] = ['key' => $progress['status'] === 'reached' ? 'reached' : 'ahead', 'tone' => 'good', 'action' => 'open_goal',
                       'params' => ['pct' => $progress['pct']]];
        }

        return array_slice($tips, 0, 3);
    }
}
