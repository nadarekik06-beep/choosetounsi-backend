<?php

namespace App\Services\GrowthRadar\Detectors;

use App\Services\GrowthRadar\Audience;
use App\Services\GrowthRadar\Benchmarks;
use App\Services\GrowthRadar\Card;
use App\Services\GrowthRadar\SellerContext;
use Illuminate\Support\Facades\DB;

/**
 * Buyers who favourited a product or left it in their cart → a coupon only they
 * can use. The seller sees a count (≥ the minimum audience), never who.
 * Skipped while a promotion runs on the product (coupons don't stack with it).
 */
class WarmAudienceDetector implements Detector
{
    use BuildsActions;

    public function __construct(private Audience $audience) {}

    public function detect(SellerContext $ctx, Benchmarks $bench): array
    {
        $cfg = config('growth.warm');
        $min = Audience::minimum();
        $cards = [];

        $candidates = $this->candidates(array_keys($ctx->products));
        foreach ($ctx->products as $pid => $p) {
            if ($p['promo_running'] || $p['stock'] < 1 || ($candidates[$pid] ?? 0) < $min) continue;
            $a = $this->audience->forProduct($ctx->sellerId, $pid);
            $n = count($a['eligible']);
            if ($n < $min) continue;

            $pct  = (int) $cfg['coupon_pct'];
            $days = (int) $cfg['coupon_days'];
            $unit = $ctx->unitRevenue($p) * (1 - $pct / 100);
            [$lo, $hi] = $cfg['buy_rate'];
            $confidence = $n >= 40 ? 'high' : ($n >= 15 ? 'medium' : 'low');

            // Split favourites / carts only when each side is itself above the minimum.
            $numbers = [['key' => 'interested_buyers', 'value' => $n]];
            if ($a['favorites'] >= $min && $a['carts'] >= $min) {
                $numbers[] = ['key' => 'favorites', 'value' => $a['favorites']];
                $numbers[] = ['key' => 'abandoned_carts', 'value' => $a['carts']];
            }

            $card = new Card('warm_audience', "warm:$pid", $pid, $confidence,
                ['product' => $p['name'], 'audience' => $n, 'pct' => $pct, 'days' => $days],
                ['numbers' => $numbers],
                $this->coupon($pid, $pct, $days, $n),
                null,
                ['audience' => 'own'],
            );
            $cards[] = $card->impact($n * $lo * $unit, $n * $hi * $unit, $ctx->learning->multiplier('coupon'));
        }
        return $cards;
    }

    /** product id => upper bound of interested buyers (cheap, before the exact audience query). */
    private function candidates(array $productIds): array
    {
        if (!$productIds) return [];
        $out = [];
        $add = function ($rows) use (&$out) {
            foreach ($rows as $pid => $n) $out[(int) $pid] = ($out[(int) $pid] ?? 0) + (int) $n;
        };
        $add(DB::table('favorites')->whereIn('product_id', $productIds)
            ->where('created_at', '>=', now()->subDays((int) config('growth.warm.favorite_days')))
            ->groupBy('product_id')->selectRaw('product_id, COUNT(*) as n')->pluck('n', 'product_id'));
        $add(DB::table('carts')->whereIn('product_id', $productIds)
            ->groupBy('product_id')->selectRaw('product_id, COUNT(*) as n')->pluck('n', 'product_id'));
        $add(DB::table('user_interactions')->whereIn('product_id', $productIds)->where('event_type', 'cart_add')
            ->whereNotNull('user_id')->where('created_at', '>=', now()->subDays((int) config('growth.warm.cart_days')))
            ->groupBy('product_id')->selectRaw('product_id, COUNT(DISTINCT user_id) as n')->pluck('n', 'product_id'));
        return $out;
    }
}
