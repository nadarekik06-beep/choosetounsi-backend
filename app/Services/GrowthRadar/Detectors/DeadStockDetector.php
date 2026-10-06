<?php

namespace App\Services\GrowthRadar\Detectors;

use App\Services\GrowthRadar\Benchmarks;
use App\Services\GrowthRadar\Card;
use App\Services\GrowthRadar\SellerContext;

/**
 * Stock that has not moved for config('growth.dead_stock.days') days.
 * Few views → it's not seen: boost it. Seen but not bought → clearance
 * discount, with "bundle it with a best-seller" as the alternative.
 */
class DeadStockDetector implements Detector
{
    use BuildsActions;

    public function detect(SellerContext $ctx, Benchmarks $bench): array
    {
        $cfg = config('growth.dead_stock');
        $cards = [];

        foreach ($ctx->products as $pid => $p) {
            if ($ctx->isClaimed($pid) || $p['promo_running'] || $p['boost_running']) continue;
            if ($p['stock'] < $cfg['min_stock'] || $p['listed_days'] < $cfg['days']) continue;

            $idle = $p['last_sale'] ? (int) $ctx->today->diffInDays(\Carbon\CarbonImmutable::parse($p['last_sale'], $ctx->today->tz)) : $p['listed_days'];
            if ($idle < $cfg['days']) continue;

            $views = $p['cur']['views'];
            $price = (float) $p['price'];
            $value = $p['stock'] * $price;
            $hidden = $views < $cfg['low_views'];
            $multi  = count($ctx->products) >= 2;

            if ($hidden) {
                $action = $this->boost($pid);
                $alt = $multi ? $this->bundle($pid) : null;
                $net = $price;
                $kind = 'boost';
            } else {
                $pct = (int) $cfg['clearance_pct'];
                $action = $this->promo('discount', [$pid], $pct, $bench->bestStart(1), (int) $cfg['clearance_days']);
                $alt = $multi ? $this->bundle($pid) : null;
                $net = $price * (1 - $pct / 100);
                $kind = 'discount';
            }

            // Units we can expect to move: a share of the stock, but never more than this
            // product ever sold in its best 30 days (at least 2) — no fantasy on big stocks.
            [$lo, $hi] = $cfg['sell_through'];
            $cap = max(2, $this->bestMonth($p['daily']));
            $unitsLo = min($p['stock'] * $lo, $cap * 0.5);
            $unitsHi = min($p['stock'] * $hi, $cap);
            $confidence = $idle >= 60 && $views >= 30 ? 'medium' : 'low';
            $card = new Card('dead_stock', "dead:$pid", $pid, $confidence,
                ['product' => $p['name'], 'days' => $idle, 'stock' => $p['stock'], 'value' => round($value),
                 'views' => $views, 'pct' => $action['discount_value'] ?? null, 'promo_days' => (int) $cfg['clearance_days'], 'focus' => $hidden ? 'visibility' : 'clearance'],
                ['numbers' => [
                    ['key' => 'days_without_sale', 'value' => $idle],
                    ['key' => 'units_in_stock', 'value' => $p['stock']],
                    ['key' => 'stock_value', 'value' => round($value), 'unit' => 'dt'],
                    ['key' => 'views_30d', 'value' => $views],
                 ]],
                $action, $alt, ['stock' => 'own'],
            );
            $cards[] = $card->impact($unitsLo * $net, $unitsHi * $net, $ctx->learning->multiplier($kind));
            $ctx->claim($pid);
        }
        return $cards;
    }

    /** Most units sold in any 30-day window of the daily series. */
    private function bestMonth(array $daily): int
    {
        $sales = array_filter(array_map(fn ($r) => $r['units'], $daily));
        if (!$sales) return 0;
        ksort($sales);
        $days = array_keys($sales);
        $units = array_values($sales);
        $best = $sum = $i = 0;
        foreach ($days as $j => $day) {                 // sliding 30-day window
            $sum += $units[$j];
            while (strtotime($day) - strtotime($days[$i]) >= 30 * 86400) $sum -= $units[$i++];
            $best = max($best, $sum);
        }
        return $best;
    }
}
