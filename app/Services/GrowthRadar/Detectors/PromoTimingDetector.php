<?php

namespace App\Services\GrowthRadar\Detectors;

use App\Services\GrowthRadar\Benchmarks;
use App\Services\GrowthRadar\Card;
use App\Services\GrowthRadar\SellerContext;

/**
 * When to launch: the platform's busiest weekday and hour (views + purchases,
 * all shops, last weeks) — only when that slot clearly beats the average. The
 * card proposes a short flash sale there on the seller's most-viewed product
 * that has nothing running and no other card.
 */
class PromoTimingDetector implements Detector
{
    use BuildsActions;

    public function detect(SellerContext $ctx, Benchmarks $bench): array
    {
        $cfg = config('growth.timing');
        $t = $bench->traffic();
        if (!$t || $t['lift'] < $cfg['min_lift']) return [];

        $candidates = array_filter($ctx->products, fn ($p) => !$ctx->isClaimed($p['id']) && !$p['promo_running'] && $p['stock'] >= 3 && $p['cur']['views'] >= 10);
        if (!$candidates) return [];
        uasort($candidates, fn ($a, $b) => $b['cur']['views'] <=> $a['cur']['views']);
        $p = reset($candidates);
        $pid = $p['id'];

        $hours = (int) $cfg['flash_hours'];
        $pct   = (int) $cfg['flash_pct'];
        $start = $bench->bestStart(1);
        $unit  = $ctx->unitRevenue($p) * (1 - $pct / 100);

        // Expected orders over the sale: own daily orders (60 days), else views × benchmark rate
        $dailyOrders = ($p['cur']['orders'] + $p['prev']['orders']) / (2 * config('growth.window_days'));
        if ($dailyOrders <= 0) {
            $conv = $bench->conversionFor($p['category_id']);
            $dailyOrders = $conv ? $p['cur']['views'] / config('growth.window_days') * $conv['rate'] : 0;
        }
        $base = $dailyOrders * $hours / 24 * $unit;
        $lift = $ctx->learning->byKind['flash_sale']['lift'] ?? null;
        [$lo, $hi] = $lift !== null && $lift > 0 ? [$lift * 0.5, $lift] : [0.3, $t['lift'] - 1 + 0.3];

        $n = $ctx->learning->byKind['flash_sale']['n'] ?? 0;
        $card = new Card('promo_timing', "timing:$pid", $pid,
            $n >= (int) config('growth.learning.min_actions') ? 'medium' : 'low',
            ['product' => $p['name'], 'day' => $t['day'], 'hour' => $t['hour'], 'lift' => $t['lift'], 'hours' => $hours,
             'pct' => $pct, 'start_date' => $start->toDateString()],
            ['numbers' => [
                ['key' => 'traffic_lift', 'value' => $t['lift'], 'unit' => 'x'],
                ['key' => 'views_30d', 'value' => $p['cur']['views']],
             ], 'chart' => ['kind' => 'traffic', 'hours' => $t['hours'], 'days' => $t['days'], 'day' => $t['day'], 'hour' => $t['hour']]],
            $this->promo('flash_sale', [$pid], $pct, $start, $hours),
            null,
            ['traffic' => 'platform'],
        );
        return [$base > 0 ? $card->impact($base * $lo, $base * $hi) : $card->impact(null, null)];
    }
}
