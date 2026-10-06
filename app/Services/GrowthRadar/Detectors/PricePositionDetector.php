<?php

namespace App\Services\GrowthRadar\Detectors;

use App\Services\GrowthRadar\Benchmarks;
use App\Services\GrowthRadar\Card;
use App\Services\GrowthRadar\SellerContext;

/**
 * Price position vs the category range (p25–p75 of live products, ≥5 shops).
 *
 *  - above the range AND converting below the benchmark → a short discount test
 *    down to about the median (a price cut always goes through a discount);
 *  - below the range AND selling well → room to raise the list price.
 *
 * A product priced above the range that still sells fine gets no card.
 */
class PricePositionDetector implements Detector
{
    use BuildsActions;

    public function detect(SellerContext $ctx, Benchmarks $bench): array
    {
        $cfg = config('growth.price');
        $cards = [];

        foreach ($ctx->products as $pid => $p) {
            if ($p['promo_running'] || $p['price'] <= 0) continue;
            $range = $bench->priceRange($p['category_id'], $p['subcategory_id']);
            if (!$range) continue;

            $views  = $p['cur']['views'];
            $orders = $p['cur']['orders'];
            $price  = (float) $p['price'];
            $conv   = $bench->conversionFor($p['category_id']);
            $rate   = $views > 0 ? $orders / $views : 0.0;
            $chart  = ['kind' => 'price_range', 'p25' => $range['p25'], 'median' => $range['median'], 'p75' => $range['p75'], 'mine' => $price];
            $rangeConfidence = $range['products'] >= 20 && $range['sellers'] >= 8 ? 'high' : 'medium';

            if ($price > $range['p75'] * $cfg['high_factor'] && $views >= 20 && (!$conv || $rate < $conv['rate'])) {
                $target = $range['median'] * 1.05;
                $pct    = $this->pctTo($price, $target);
                $newPrice = round($price * (1 - $pct / 100), 1);
                $benchRate = $conv['rate'] ?? (float) config('growth.leaking.fallback_rate');
                $days   = (int) $cfg['test_days'];
                $perDay = $views / max(1, $p['cur']['days']);
                $gain   = max(0, $benchRate - $rate) * $perDay * $days * $newPrice;

                $confidence = Card::weaker(Card::confidenceFromViews($views), $rangeConfidence);
                $card = new Card('price_position', "price:$pid:high", $pid, $confidence,
                    ['product' => $p['name'], 'price' => $price, 'median' => $range['median'], 'pct' => $pct, 'days' => $days,
                     'new_price' => $newPrice, 'above_pct' => (int) round(($price / $range['median'] - 1) * 100), 'direction' => 'high'],
                    ['numbers' => array_values(array_filter([
                        ['key' => 'your_price', 'value' => $price, 'unit' => 'dt'],
                        ['key' => 'category_median', 'value' => $range['median'], 'unit' => 'dt'],
                        $conv ? ['key' => 'conversion', 'value' => self::pct($rate, 2), 'unit' => '%', 'compare' => self::pct($conv['rate'], 2)] : null,
                     ])), 'chart' => $chart],
                    $this->promo('discount', [$pid], $pct, $bench->bestStart(1), $days),
                    null,
                    ['price' => $range['scope'], 'conversion' => $conv['scope'] ?? 'none'],
                );
                $cards[] = $card->impact($gain * 0.3, $gain * 0.7, $ctx->learning->multiplier('discount'));
                $ctx->claim($pid);
                continue;
            }

            $units = $p['cur']['units'];
            if ($price < $range['p25'] * $cfg['low_factor'] && $units >= 3) {
                $newPrice = round(min($range['p25'], $price * 1.15), 1);
                if ($newPrice <= $price) continue;
                $gain = $units * ($newPrice - $price);
                $confidence = Card::weaker($units >= 30 ? 'high' : ($units >= 10 ? 'medium' : 'low'), $rangeConfidence);
                $card = new Card('price_position', "price:$pid:low", $pid, $confidence,
                    ['product' => $p['name'], 'price' => $price, 'median' => $range['median'], 'new_price' => $newPrice,
                     'below_pct' => (int) round((1 - $price / $range['median']) * 100), 'units' => $units, 'direction' => 'low'],
                    ['numbers' => [
                        ['key' => 'your_price', 'value' => $price, 'unit' => 'dt'],
                        ['key' => 'category_median', 'value' => $range['median'], 'unit' => 'dt'],
                        ['key' => 'units_30d', 'value' => $units],
                     ], 'chart' => $chart],
                    $this->edit($pid, 'price', $newPrice),
                    null,
                    ['price' => $range['scope']],
                );
                // Some buyers may drop off at the higher price: 50–90 % of the arithmetic gain.
                $cards[] = $card->impact($gain * 0.5, $gain * 0.9);
                $ctx->claim($pid);
            }
        }
        return $cards;
    }
}

