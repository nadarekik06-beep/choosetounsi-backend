<?php

namespace App\Services\Forecast;

/**
 * "Toute la boutique": sums the product forecasts. Expected units add up, and
 * so do variances (products treated as independent), so the shop range is
 * narrower than the sum of the product ranges — as it should be.
 */
class ShopAggregator
{
    /** @param array $items [['product' => meta, 'result' => forecast], …] live products only */
    public function aggregate(array $items, array $actions): array
    {
        $withDist = array_values(array_filter($items, fn($i) => $i['result']['dist'] !== null));
        $tiers    = array_count_values(array_map(fn($i) => $i['result']['tier'], $items));

        $base = [
            'scope'   => 'shop',
            'tiers'   => $tiers,
            'history' => $this->sumHistory($items),
            'signals' => $this->sumSignals($items),
            'events'  => $this->events($items),
            'actions' => $actions,
            'stock'   => $this->stock($items),
            'products_count' => count($items),
        ];

        if (!$withDist) {
            return $base + ['tier' => 'insufficient', 'confidence' => ['score' => 0, 'level' => 'none'],
                            'weeks' => [], 'months' => [], 'next28' => null, 'unit_price' => null, 'dist' => null];
        }

        $sum = fn(callable $pick) => array_reduce($withDist, function ($acc, $i) use ($pick) {
            [$m, $v] = $pick($i['result']['dist']);
            return [$acc[0] + $m, $acc[1] + $v, $acc[2] + $m * $i['result']['unit_price']];
        }, [0.0, 0.0, 0.0]);

        $view = function (array $t) {
            [$mean, $var, $rev] = $t;
            $price = $mean > 0 ? $rev / $mean : 0.0;
            [$low, $high] = Stats::interval($mean, $var);
            return ['point' => (int) round($mean), 'low' => $low, 'high' => $high,
                    'revenue_point' => round($rev, 3), 'revenue_low' => round($low * $price, 3),
                    'revenue_high' => round($high * $price, 3), 'mean' => round($mean, 3), 'var' => round($var, 3)];
        };

        $first = $withDist[0]['result'];
        $weeks = [];
        foreach ($first['weeks'] as $k => $w) {
            $weeks[] = $view($sum(fn($d) => $d['weeks'][$k])) + ['start' => $w['start'], 'end' => $w['end']];
        }
        $months = [];
        foreach ($first['months'] as $k => $m) {
            $months[] = $view($sum(fn($d) => $d['months'][$k])) + ['month' => $m['month']];
        }
        $n28 = $sum(fn($d) => $d['next28']);

        // Confidence: product scores weighted by the units they're expected to sell.
        $wSum = 0.0; $sSum = 0.0;
        foreach ($withDist as $i) {
            $w = max(0.1, $i['result']['dist']['next28'][0]);
            $wSum += $w; $sSum += $w * $i['result']['confidence']['score'];
        }
        $score = (int) round($sSum / $wSum);
        arsort($tiers);

        return $base + [
            'tier'       => array_key_first(array_filter($tiers, fn($n, $t) => $t !== 'insufficient', ARRAY_FILTER_USE_BOTH)) ?? 'insufficient',
            'confidence' => ['score' => $score, 'level' => $score >= 75 ? 'high' : ($score >= 45 ? 'medium' : 'low'),
                             'orders' => array_sum(array_map(fn($i) => $i['result']['data']['orders'], $items))],
            'weeks'      => $weeks,
            'months'     => $months,
            'next28'     => $view($n28),
            'unit_price' => $n28[0] > 0 ? round($n28[2] / $n28[0], 3) : null,
            'dist'       => ['next28' => [$n28[0], $n28[1]],
                             'weeks' => array_map(fn($w) => [$w['mean'], $w['var']], $weeks),
                             'months' => array_map(fn($m) => [$m['mean'], $m['var']], $months)],
        ];
    }

    private function sumHistory(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            foreach ($i['result']['history'] as $k => $h) {
                $out[$k] ??= ['start' => $h['start'], 'units' => 0, 'promo' => false];
                $out[$k]['units'] += $h['units'];
                $out[$k]['promo'] = $out[$k]['promo'] || $h['promo'];
            }
        }
        return array_values($out);
    }

    private function sumSignals(array $items): array
    {
        $s = ['views_30d' => 0, 'cart_adds_30d' => 0, 'favorites_30d' => 0, 'orders_30d' => 0];
        foreach ($items as $i) foreach ($s as $k => $_) $s[$k] += $i['result']['signals'][$k];
        $s['cart_rate']       = $s['views_30d'] > 0 ? round($s['cart_adds_30d'] / $s['views_30d'], 4) : null;
        $s['conversion_rate'] = $s['views_30d'] > 0 ? round($s['orders_30d'] / $s['views_30d'], 4) : null;
        $s['category_cart_rate'] = null;
        return $s;
    }

    private function events(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            foreach ($i['result']['events'] as $ev) {
                // Effects are per category; at shop level only the dates are shown.
                $out[$ev['id']] ??= ['effect' => null, 'applied' => false] + $ev;
            }
        }
        usort($out, fn($a, $b) => strcmp($a['starts_on'], $b['starts_on']));
        return array_values($out);
    }

    private function stock(array $items): array
    {
        $atRisk = [];
        $units  = 0;
        foreach ($items as $i) {
            $s = $i['result']['stock'];
            $units += max(0, $s['current']);
            $rows = $i['result']['variants'] ? array_column($i['result']['variants'], 'stock') : [$s];
            $soonest = null;
            foreach ($rows as $r) {
                if ($r['days_left'] !== null && $r['days_left'] <= $r['lead_time_days'] + $r['safety_days'] + 7) {
                    $soonest = $soonest === null ? $r['days_left'] : min($soonest, $r['days_left']);
                }
            }
            if ($soonest !== null) {
                $atRisk[] = ['product_id' => $i['product']['id'], 'name' => $i['product']['name'], 'days_left' => $soonest];
            }
        }
        usort($atRisk, fn($a, $b) => $a['days_left'] <=> $b['days_left']);
        return ['current' => $units, 'at_risk' => $atRisk];
    }
}
