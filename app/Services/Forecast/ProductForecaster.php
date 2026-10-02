<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;

/**
 * Forecast for one product (and its variants) from plain arrays — no DB access,
 * so every scenario is unit-testable. App\Services\Forecast\ForecastService
 * loads the inputs and stores the result.
 *
 * Input keys:
 *   today          'Y-m-d' (shop timezone)
 *   listed_since   'Y-m-d' first day the product was on sale
 *   daily          ['Y-m-d' => [units, net_revenue, orders, promo_units, promo_day, views, cart_adds, favorites]]
 *   variants       [variant_id => ['labels' => [fr,en,ar], 'stock' => int, 'daily' => ['Y-m-d' => units]]]
 *   stock          int   current stock (sum of active variants when the product has variants)
 *   price          float current price (fallback for revenue)
 *   prior          null | ['rates' => float[] per-day rates of similar products, 'products', 'sellers', 'orders', 'scope', 'cart_rate' => ?float]
 *   events         [['id','key','names' => [fr,en,ar],'starts_on','ends_on','effect' => null|['change_pct','event_orders','baseline_orders','year']]]
 *   promos         [['starts_on','ends_on']] scheduled promotions of this product
 *   lead_time_days, safety_days
 *   previous       null | ['snapshot_date','h28_point','h28_low','h28_high']  forecast made ~28 days ago
 *   drop_check     null | ['since','expected_low','expected_point']          forecast made 14 days ago for the last 14 days
 */
final class ProductForecaster
{
    private const WEEKS_AHEAD = 32;

    public function __construct(private array $cfg = []) {}

    public function forecast(array $in): array
    {
        $cfg   = $this->cfg + config('forecast');
        $today = CarbonImmutable::parse($in['today']);
        $since = CarbonImmutable::parse($in['listed_since'])->max($today->subDays($cfg['history_days']));
        $daily = $in['daily'] ?? [];

        // ── Observed history (yesterday and before) ─────────────────────────
        $days        = max(0, (int) $since->diffInDays($today));          // full days observed
        $hist        = $this->dailyArrays($daily, $since, $days);
        $orders      = array_sum($hist['orders']);
        $promoShare  = $days > 0 ? array_sum($hist['promo']) / $days : 0.0;
        // A promotion/boost running most of the time *is* the normal state: keep those days.
        $excludePromo = $promoShare > 0 && $promoShare <= 0.5;

        $weekly = $this->weeklySeries($hist, $excludePromo);
        $uplift = $this->promoUplift($hist, $cfg);
        $prior  = $this->gammaPrior($in['prior'] ?? null, $cfg);

        // ── Tier ────────────────────────────────────────────────────────────
        if ($orders >= $cfg['own_min_orders'] && $days >= $cfg['own_min_days'] && count($weekly['adj']) >= 8) {
            $tier = 'own';
        } elseif ($orders === 0) {
            $tier = $prior ? 'category' : 'insufficient';
        } else {
            $tier = $prior ? 'blend' : 'limited';
        }

        $base = [
            'tier'        => $tier,
            'data'        => [
                'orders'        => $orders,
                'units'         => array_sum($hist['units']),
                'days'          => $days,
                'last_sale'     => $this->lastSale($hist, $since),
                'promo_days'    => array_sum($hist['promo']),
                'promo_excluded'=> $excludePromo,
                'intermittent'  => Models::isIntermittent($weekly['adj']),
            ],
            'prior'       => !empty($in['prior']) ? [
                'products' => $in['prior']['products'], 'sellers' => $in['prior']['sellers'],
                'orders' => $in['prior']['orders'], 'scope' => $in['prior']['scope'],
            ] : null,
            'promo_uplift'=> $uplift,
            'history'     => $this->historyWeeks($daily, $today),
            'signals'     => $this->signals($hist, $in['prior']['cart_rate'] ?? null),
            'events'      => $this->eventsView($in['events'] ?? [], $today, $cfg),
        ];

        if ($tier === 'insufficient') {
            return $base + [
                'model' => null, 'confidence' => ['score' => 0, 'level' => 'none', 'orders' => 0, 'days' => $days, 'error_pct' => null],
                'weeks' => [], 'months' => [], 'next28' => null, 'unit_price' => (float) $in['price'],
                'stock' => $this->stockView($in, null, $today, $cfg), 'variants' => [],
                'dist'  => null,
            ];
        }

        // ── Daily expected demand for the next WEEKS_AHEAD weeks ────────────
        $multipliers = $this->dayMultipliers($in, $today, $uplift, $cfg);

        if ($tier === 'own') {
            $sel   = Models::select($weekly['adj']);
            $wk    = Models::forecast($sel['model'], $weekly['adj'], self::WEEKS_AHEAD);
            $rates = [];
            foreach ($wk as $k => $w) for ($d = 0; $d < 7; $d++) $rates[] = $w / 7 * $multipliers[$k * 7 + $d];
            $recent    = array_slice($weekly['adj'], -26);
            $phi       = Stats::mean($recent) > 0 ? min(8.0, max(1.0, Stats::variance($recent) / Stats::mean($recent))) : 1.0;
            $paramCv   = 1 / sqrt(max(1, $orders));
            $bucket    = fn(int $from, int $len) => $this->ownBucket($rates, $from, $len, $phi, $paramCv);
            $model     = $sel['model'];
            $recentAvg = Stats::mean(array_slice($weekly['adj'], -8));
            $errorPct  = $sel['mae'] !== null ? (int) round(100 * $sel['mae'] / max($recentAvg, 0.25)) : null;
            $weightOwn = 1.0;
        } else {
            // Gamma–Poisson: prior from similar products, updated with the seller's
            // own non-promo days. The seller's weight grows with their exposure.
            // 'limited' (no category prior): weak prior of half a sale per two weeks,
            // so one early sale can't be extrapolated wildly; a few orders outweigh it.
            [$a0, $b0] = $prior ?? [0.5, 14.0];
            $t   = max(0, $days - ($excludePromo ? array_sum($hist['promo']) : 0));
            $y   = $excludePromo ? $weekly['nonpromo_units'] : array_sum($hist['units']);
            $a   = $a0 + $y;
            $b   = max($b0 + $t, 1.0);
            $rates = [];
            for ($d = 0; $d < self::WEEKS_AHEAD * 7; $d++) $rates[] = $a / $b * $multipliers[$d];
            $bucket    = fn(int $from, int $len) => $this->gammaBucket($rates, $from, $len, $a, $b);
            $model     = $tier === 'category' ? 'category_prior' : ($tier === 'blend' ? 'shrinkage' : 'poisson_gamma');
            $errorPct  = null;
            $weightOwn = $prior ? round($t / ($b0 + $t), 2) : 1.0;
        }

        $price = $this->unitPrice($hist, (float) $in['price']);

        $weeks = [];
        for ($k = 0; $k < 4; $k++) {
            [$mean, $var] = $bucket($k * 7, 7);
            $weeks[] = $this->bucketView($mean, $var, $price) + [
                'start' => $today->addDays($k * 7)->toDateString(),
                'end'   => $today->addDays($k * 7 + 6)->toDateString(),
                'mean'  => round($mean, 3), 'var' => round($var, 3),
            ];
        }
        [$m28, $v28] = $bucket(0, 28);
        $next28 = $this->bucketView($m28, $v28, $price) + ['mean' => round($m28, 3), 'var' => round($v28, 3)];

        $months = [];
        $monthStart = $today->startOfMonth()->addMonth();
        for ($i = 0; $i < 6; $i++) {
            $ms   = $monthStart->addMonths($i);
            $from = (int) $today->diffInDays($ms);
            $len  = $ms->daysInMonth;
            [$mean, $var] = $bucket($from, $len);
            $months[] = $this->bucketView($mean, $var, $price) + [
                'month' => $ms->format('Y-m'), 'mean' => round($mean, 3), 'var' => round($var, 3),
            ];
        }

        $confidence = $this->confidence($tier, $orders, $days, $errorPct, $in['prior'] ?? null);

        $result = $base + [
            'model'      => $model,
            'weight_own' => $weightOwn,
            'confidence' => $confidence,
            'weeks'      => $weeks,
            'months'     => $months,
            'next28'     => $next28,
            'unit_price' => round($price, 3),
        ];

        $result['stock']    = $this->stockView($in, $bucket, $today, $cfg);
        $result['variants'] = $this->variantViews($in, $next28, $bucket, $today, $cfg);
        $result['dist']     = ['next28' => [$m28, $v28], 'weeks' => array_map(fn($w) => [$w['mean'], $w['var']], $weeks),
                               'months' => array_map(fn($m) => [$m['mean'], $m['var']], $months)];
        return $result;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Series
    // ═════════════════════════════════════════════════════════════════════

    private function dailyArrays(array $daily, CarbonImmutable $since, int $days): array
    {
        $out = ['units' => [], 'revenue' => [], 'orders' => [], 'promo_units' => [], 'promo' => [], 'views' => [], 'carts' => [], 'favs' => []];
        for ($i = 0; $i < $days; $i++) {
            $r = $daily[$since->addDays($i)->toDateString()] ?? null;
            $out['units'][]       = (int) ($r['units'] ?? 0);
            $out['revenue'][]     = (float) ($r['net_revenue'] ?? 0);
            $out['orders'][]      = (int) ($r['orders'] ?? 0);
            $out['promo_units'][] = (int) ($r['promo_units'] ?? 0);
            $out['promo'][]       = (int) (!empty($r['promo_day']) || ($r['promo_units'] ?? 0) > 0);
            $out['views'][]       = (int) ($r['views'] ?? 0);
            $out['carts'][]       = (int) ($r['cart_adds'] ?? 0);
            $out['favs'][]        = (int) ($r['favorites'] ?? 0);
        }
        return $out;
    }

    /**
     * Full weeks ending yesterday. With $excludePromo, promo days are taken out
     * and the week is rescaled from its normal days (< 3 normal days → filled
     * with the average of normal weeks).
     */
    private function weeklySeries(array $hist, bool $excludePromo): array
    {
        $n     = count($hist['units']);
        $weeks = intdiv($n, 7);
        $offset = $n - $weeks * 7;          // drop the oldest partial week
        $adj = []; $missing = []; $nonPromoUnits = 0;

        for ($i = 0; $i < $n; $i++) {
            if (!$excludePromo || !$hist['promo'][$i]) $nonPromoUnits += $hist['units'][$i];
        }
        for ($w = 0; $w < $weeks; $w++) {
            $units = 0; $normalDays = 0;
            for ($d = 0; $d < 7; $d++) {
                $i = $offset + $w * 7 + $d;
                if ($excludePromo && $hist['promo'][$i]) continue;
                $units += $hist['units'][$i];
                $normalDays++;
            }
            if ($normalDays >= 3) {
                $adj[] = $units * 7 / $normalDays;
            } else {
                $adj[] = null;
                $missing[] = $w;
            }
        }
        $fill = Stats::mean(array_filter($adj, fn($v) => $v !== null));
        foreach ($missing as $w) $adj[$w] = $fill;

        return ['adj' => array_values($adj), 'nonpromo_units' => $nonPromoUnits];
    }

    /** Sales rate on promo days vs normal days — reported, used only for scheduled promotions. */
    private function promoUplift(array $hist, array $cfg): ?array
    {
        $pDays = $pUnits = $pOrders = $nDays = $nUnits = 0;
        foreach ($hist['units'] as $i => $u) {
            if ($hist['promo'][$i]) { $pDays++; $pUnits += $u; $pOrders += $hist['orders'][$i]; }
            else { $nDays++; $nUnits += $u; }
        }
        if ($pDays === 0 || $nDays === 0 || $nUnits === 0) return null;
        $factor = ($pUnits / $pDays) / ($nUnits / $nDays);
        return [
            'factor'      => round($factor, 2),
            'promo_days'  => $pDays,
            'promo_orders'=> $pOrders,
            'applied'     => $pOrders >= $cfg['promo_uplift_min_orders'],
        ];
    }

    /** Gamma(a, b) fitted to similar products' daily rates; null when too thin. */
    private function gammaPrior(?array $prior, array $cfg): ?array
    {
        if (!$prior || empty($prior['rates'])) return null;
        $m = Stats::mean($prior['rates']);
        if ($m <= 0) return null;
        $v = max(Stats::variance($prior['rates']), $m * 1e-3);
        $b = min($m / $v, $cfg['prior']['max_strength_days']);
        return [$m * $b, $b];
    }

    private function dayMultipliers(array $in, CarbonImmutable $today, ?array $uplift, array $cfg): array
    {
        $mult = array_fill(0, self::WEEKS_AHEAD * 7, 1.0);

        foreach ($in['events'] ?? [] as $ev) {
            $e = $ev['effect'] ?? null;
            if (!$e || $e['change_pct'] === null) continue;
            if ($e['event_orders'] < $cfg['event_effect_min_orders'] || $e['baseline_orders'] < $cfg['event_effect_min_orders']) continue;
            $f = min(3.0, max(0.3, 1 + $e['change_pct'] / 100));
            $this->applyWindow($mult, $today, $ev['starts_on'], $ev['ends_on'], $f);
        }

        if ($uplift && $uplift['applied']) {
            foreach ($in['promos'] ?? [] as $p) {
                $this->applyWindow($mult, $today, $p['starts_on'], $p['ends_on'], min(4.0, max(1.0, $uplift['factor'])));
            }
        }
        return $mult;
    }

    private function applyWindow(array &$mult, CarbonImmutable $today, string $start, string $end, float $f): void
    {
        $s = (int) $today->diffInDays(CarbonImmutable::parse($start), false);
        $e = (int) $today->diffInDays(CarbonImmutable::parse($end), false);
        for ($d = max(0, $s); $d <= min(count($mult) - 1, $e); $d++) $mult[$d] *= $f;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Buckets & ranges
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Own model: variance = φ·μ (count noise, φ = observed over-dispersion)
     * + (c·μ)² (uncertainty on the level, c = 1/√orders, growing with horizon).
     */
    private function ownBucket(array $rates, int $from, int $len, float $phi, float $cv): array
    {
        $mean = array_sum(array_slice($rates, $from, $len));
        $c    = $cv * sqrt(max(1.0, ($from + $len / 2) / 28));
        return [$mean, $phi * $mean + ($c * $mean) ** 2];
    }

    /** Gamma–Poisson predictive: mean = aH/b, variance = mean + a(H/b)². */
    private function gammaBucket(array $rates, int $from, int $len, float $a, float $b): array
    {
        $mean  = array_sum(array_slice($rates, $from, $len));
        $scale = $a > 0 ? $mean / $a : 0.0;   // = H·mult / b
        return [$mean, $mean + $a * $scale ** 2];
    }

    private function bucketView(float $mean, float $var, float $price): array
    {
        [$low, $high] = Stats::interval($mean, $var);
        return [
            'point'         => (int) round($mean),
            'low'           => $low,
            'high'          => $high,
            'revenue_point' => round($mean * $price, 3),
            'revenue_low'   => round($low * $price, 3),
            'revenue_high'  => round($high * $price, 3),
        ];
    }

    private function unitPrice(array $hist, float $fallback): float
    {
        $units = array_sum(array_slice($hist['units'], -180));
        $rev   = array_sum(array_slice($hist['revenue'], -180));
        return $units >= 3 && $rev > 0 ? $rev / $units : $fallback;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Confidence
    // ═════════════════════════════════════════════════════════════════════

    private function confidence(string $tier, int $orders, int $days, ?int $errorPct, ?array $prior): array
    {
        // Data: orders (log scale, full at 50) and listing history (full at 6 months).
        $data     = 100 * (0.67 * min(1.0, log(1 + $orders) / log(51)) + 0.33 * min(1.0, $days / 180));
        // Accuracy: backtest error as % of a normal week (no backtest → 0).
        $accuracy = $errorPct === null ? 0.0 : 100 * max(0.0, 1 - $errorPct / 100);

        $score = match ($tier) {
            'category'          => 5 + min(15, (int) ($prior['products'] ?? 0)),
            'blend', 'limited'  => min(45.0, 0.45 * $data),
            'own'               => 0.4 * $data + 0.6 * $accuracy,
        };
        $score = (int) round(min(100, max(0, $score)));

        return [
            'score'     => $score,
            'level'     => $score >= 75 ? 'high' : ($score >= 45 ? 'medium' : 'low'),
            'orders'    => $orders,
            'days'      => $days,
            'error_pct' => $errorPct,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Stock
    // ═════════════════════════════════════════════════════════════════════

    private function stockView(array $in, ?\Closure $bucket, CarbonImmutable $today, array $cfg, ?float $share = null, ?int $stock = null): array
    {
        $stock = $stock ?? (int) $in['stock'];
        $lead  = (int) $in['lead_time_days'];
        $safe  = (int) $in['safety_days'];
        $view  = ['current' => $stock, 'lead_time_days' => $lead, 'safety_days' => $safe,
                  'days_left' => null, 'stockout_date' => null, 'reorder_qty' => 0, 'reorder_by' => null];
        if (!$bucket) return $view;

        $share ??= 1.0;
        [$m28] = $bucket(0, 28);
        $rate  = $m28 * $share / 28;
        if ($rate <= 0) return $view;

        // Walk the daily expected demand (events included) until stock runs out.
        $left = $stock; $day = null;
        for ($d = 0; $d < self::WEEKS_AHEAD * 7; $d++) {
            [$m] = $bucket($d, 1);
            $left -= $m * $share;
            if ($left < 0) { $day = $d; break; }
        }
        if ($stock <= 0) $day = 0;
        if ($day !== null) {
            $view['days_left']     = $day;
            $view['stockout_date'] = $today->addDays($day)->toDateString();
        }

        // Order enough to cover lead time + 4 weeks at the high end of the range,
        // plus the safety margin; reorder when stock-out is within lead + safety + 4 weeks.
        $horizon = $lead + $cfg['reorder_cover_days'];
        [$mH, $vH] = $bucket(0, $horizon);
        $high = Stats::quantile($mH * $share, max($vH * $share, $mH * $share), 0.9);
        $need = (int) ceil($high + $safe * $rate - $stock);
        if ($day !== null && $day <= $lead + $safe + $cfg['reorder_cover_days'] && $need > 0) {
            $view['reorder_qty'] = $need;
            $view['reorder_by']  = $today->addDays(max(0, $day - $lead - $safe))->toDateString();
        }
        return $view;
    }

    /**
     * Variants share the product forecast by their sales mix (with +1 smoothing
     * so a variant without sales yet still gets a small share). Sellers restock
     * by variant, but per-variant series are too sparse to model one by one.
     */
    private function variantViews(array $in, array $next28, \Closure $bucket, CarbonImmutable $today, array $cfg): array
    {
        $variants = $in['variants'] ?? [];
        if (!$variants) return [];

        $from = $today->subDays(180)->toDateString();
        $sold = [];
        foreach ($variants as $id => $v) {
            $sold[$id] = 0;
            foreach ($v['daily'] ?? [] as $day => $u) if ($day >= $from && $day < $today->toDateString()) $sold[$id] += $u;
        }
        $total = array_sum($sold) + count($variants);

        $out = [];
        foreach ($variants as $id => $v) {
            $share = ($sold[$id] + 1) / $total;
            $mean  = $next28['mean'] * $share;
            $var   = max($mean, $next28['var'] * $share);
            [$low, $high] = Stats::interval($mean, $var);
            $out[] = [
                'variant_id' => (int) $id,
                'labels'     => $v['labels'],
                'share'      => round($share, 3),
                'sold_180d'  => $sold[$id],
                'next28'     => ['point' => (int) round($mean), 'low' => $low, 'high' => $high],
                'stock'      => $this->stockView($in, $bucket, $today, $cfg, $share, (int) $v['stock']),
            ];
        }
        usort($out, fn($a, $b) => ($a['stock']['days_left'] ?? PHP_INT_MAX) <=> ($b['stock']['days_left'] ?? PHP_INT_MAX));
        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Views
    // ═════════════════════════════════════════════════════════════════════

    /** Last 13 weeks of real sales (raw, promo weeks flagged) for the chart. */
    private function historyWeeks(array $daily, CarbonImmutable $today): array
    {
        $out = [];
        for ($w = 12; $w >= 0; $w--) {
            $start = $today->subDays(7 * ($w + 1));
            $units = 0; $promo = false;
            for ($d = 0; $d < 7; $d++) {
                $r = $daily[$start->addDays($d)->toDateString()] ?? null;
                $units += (int) ($r['units'] ?? 0);
                $promo = $promo || !empty($r['promo_day']) || ($r['promo_units'] ?? 0) > 0;
            }
            $out[] = ['start' => $start->toDateString(), 'units' => $units, 'promo' => $promo];
        }
        return $out;
    }

    private function signals(array $hist, ?float $categoryCartRate): array
    {
        $views  = array_sum(array_slice($hist['views'], -30));
        $carts  = array_sum(array_slice($hist['carts'], -30));
        $orders = array_sum(array_slice($hist['orders'], -30));
        return [
            'views_30d'          => $views,
            'cart_adds_30d'      => $carts,
            'favorites_30d'      => array_sum(array_slice($hist['favs'], -30)),
            'orders_30d'         => $orders,
            'cart_rate'          => $views > 0 ? round($carts / $views, 4) : null,
            'conversion_rate'    => $views > 0 ? round($orders / $views, 4) : null,
            'category_cart_rate' => $categoryCartRate,
        ];
    }

    private function lastSale(array $hist, CarbonImmutable $since): ?string
    {
        for ($i = count($hist['units']) - 1; $i >= 0; $i--) {
            if ($hist['units'][$i] > 0) return $since->addDays($i)->toDateString();
        }
        return null;
    }

    private function eventsView(array $events, CarbonImmutable $today, array $cfg): array
    {
        $out = [];
        foreach ($events as $ev) {
            $e = $ev['effect'] ?? null;
            $out[] = [
                'id'         => $ev['id'],
                'key'        => $ev['key'],
                'names'      => $ev['names'],
                'starts_on'  => $ev['starts_on'],
                'ends_on'    => $ev['ends_on'],
                'days_until' => (int) $today->diffInDays(CarbonImmutable::parse($ev['starts_on']), false),
                'effect'     => $e,
                'applied'    => $e && $e['change_pct'] !== null
                    && $e['event_orders'] >= $cfg['event_effect_min_orders']
                    && $e['baseline_orders'] >= $cfg['event_effect_min_orders'],
            ];
        }
        return $out;
    }
}
