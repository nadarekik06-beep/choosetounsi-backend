<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;

/**
 * "Actions recommandées": turns a forecast into concrete next steps, each with
 * links to the existing seller tools. Texts are built client-side (FR/EN/AR)
 * from `type` + `params`; e-mails use lang/forecast.php with the same params.
 *
 * severity: 1 = act now, 2 = this month, 3 = tip
 */
class ActionBuilder
{
    public function forProduct(array $product, array $result, CarbonImmutable $today, ?array $dropCheck = null): array
    {
        $cfg     = config('forecast');
        $pid     = $product['id'];
        $stock   = $result['stock'];
        $signals = $result['signals'];
        $actions = [];
        $links   = [
            'restock'     => ['kind' => 'restock',     'href' => "/seller/products?restock={$pid}"],
            'discount'    => ['kind' => 'discount',    'href' => "/seller/promotions?create=discount&product={$pid}"],
            'price'       => ['kind' => 'price',       'href' => "/seller/ai-tools?tab=price&product_id={$pid}"],
            'description' => ['kind' => 'description', 'href' => "/seller/products?edit={$pid}"],
            'packs'       => ['kind' => 'packs',       'href' => '/seller/packs'],
            'promote'     => ['kind' => 'promote',     'href' => "/seller/promote/new?product_id={$pid}"],
        ];
        $base = ['product_id' => $pid, 'product_name' => $product['name']];

        // ── Stock-outs (per variant when the product has variants) ──────────
        $alertWindow = $stock['lead_time_days'] + $stock['safety_days'] + 7;
        $stockRows = $result['variants']
            ? array_map(fn($v) => ['labels' => $v['labels'], 'variant_id' => $v['variant_id'], 'stock' => $v['stock']], $result['variants'])
            : [['labels' => null, 'variant_id' => 0, 'stock' => $stock]];
        foreach ($stockRows as $row) {
            $s = $row['stock'];
            if ($s['days_left'] === null || $s['days_left'] > $alertWindow) continue;
            $actions[] = [
                'type'     => $s['current'] <= 0 ? 'out_of_stock' : 'stockout',
                'severity' => $s['days_left'] <= $s['lead_time_days'] ? 1 : 2,
                'params'   => $base + [
                    'variant_id' => $row['variant_id'], 'variant' => $row['labels'],
                    'days_left' => $s['days_left'], 'stockout_date' => $s['stockout_date'],
                    'reorder_qty' => $s['reorder_qty'], 'reorder_by' => $s['reorder_by'], 'stock' => $s['current'],
                ],
                'links'    => [$links['restock']],
            ];
        }

        // ── Upcoming events (reminders; measured effect when we have one) ───
        [$minDays, $maxDays] = $cfg['event_reminder_days'];
        foreach ($result['events'] as $ev) {
            if ($ev['days_until'] < $minDays || $ev['days_until'] > $maxDays) continue;
            $actions[] = [
                'type'     => 'event',
                'severity' => $ev['days_until'] <= 28 ? 2 : 3,
                'params'   => $base + [
                    'event_id' => $ev['id'], 'event' => $ev['names'], 'days_until' => $ev['days_until'],
                    'starts_on' => $ev['starts_on'],
                    'change_pct' => $ev['effect']['change_pct'] ?? null, 'effect_year' => $ev['effect']['year'] ?? null,
                    'effect_orders' => $ev['effect']['event_orders'] ?? null, 'effect_reliable' => $ev['applied'],
                ],
                'links'    => [$links['restock'], $links['discount']],
            ];
        }

        // ── Sales below the range we forecast two weeks ago ─────────────────
        if ($dropCheck && $dropCheck['actual'] < $dropCheck['expected_low'] && $dropCheck['expected_point'] >= 3) {
            $actions[] = [
                'type'     => 'sales_drop',
                'severity' => 2,
                'params'   => $base + $dropCheck,
                'links'    => [$links['price'], $links['promote']],
            ];
        }

        // ── Many views, few add-to-carts ────────────────────────────────────
        $threshold = $signals['category_cart_rate'] !== null ? $signals['category_cart_rate'] * 0.5 : $cfg['low_cart_rate'];
        if ($signals['views_30d'] >= $cfg['low_cart_min_views'] && $signals['cart_rate'] !== null && $signals['cart_rate'] < $threshold) {
            $actions[] = [
                'type'     => 'low_cart',
                'severity' => 2,
                'params'   => $base + [
                    'views' => $signals['views_30d'], 'cart_adds' => $signals['cart_adds_30d'],
                    'cart_rate_pct' => round($signals['cart_rate'] * 100, 1),
                    'category_rate_pct' => $signals['category_cart_rate'] !== null ? round($signals['category_cart_rate'] * 100, 1) : null,
                ],
                'links'    => [$links['price'], $links['description']],
            ];
        }

        // ── Dormant stock ───────────────────────────────────────────────────
        $listedDays = (int) CarbonImmutable::parse($product['listed_since'])->diffInDays($today);
        $last = $result['data']['last_sale'];
        $idle = $last ? (int) CarbonImmutable::parse($last)->diffInDays($today) : $listedDays;
        if ($stock['current'] >= $cfg['dormant_min_stock'] && $listedDays >= $cfg['dormant_days'] && $idle >= $cfg['dormant_days']) {
            $actions[] = [
                'type'     => 'dormant',
                'severity' => 2,
                'params'   => $base + ['stock' => $stock['current'], 'days_without_sale' => $idle, 'never_sold' => $last === null],
                'links'    => [$links['discount'], $links['packs']],
            ];
        }

        // ── Not enough data / not seen ──────────────────────────────────────
        if ($result['tier'] === 'insufficient') {
            $actions[] = [
                'type'     => 'no_data',
                'severity' => 3,
                'params'   => $base + ['views' => $signals['views_30d']],
                'links'    => [$links['description'], $links['price'], $links['promote']],
            ];
        } elseif ($product['live'] && $signals['views_30d'] < $cfg['low_views_max'] && $result['tier'] !== 'own') {
            $actions[] = [
                'type'     => 'low_views',
                'severity' => 3,
                'params'   => $base + ['views' => $signals['views_30d']],
                'links'    => [$links['promote'], $links['description']],
            ];
        }

        usort($actions, fn($a, $b) => [$a['severity'], $a['params']['days_left'] ?? 999] <=> [$b['severity'], $b['params']['days_left'] ?? 999]);
        return $actions;
    }

    /** Shop view: the most urgent actions across products, events once. */
    public function forShop(array $productActions, int $limit = 10): array
    {
        $all = []; $seenEvents = [];
        foreach ($productActions as $actions) {
            foreach ($actions as $a) {
                if ($a['type'] === 'event') {
                    if (isset($seenEvents[$a['params']['event_id']])) continue;
                    $seenEvents[$a['params']['event_id']] = true;
                    // Shop-wide reminder: link to the promotions page without a product
                    $a['params']['product_id'] = null;
                    $a['params']['product_name'] = null;
                    $a['links'] = [['kind' => 'discount', 'href' => '/seller/promotions']];
                }
                if ($a['type'] === 'no_data' || $a['type'] === 'low_views') continue;
                $all[] = $a;
            }
        }
        usort($all, fn($a, $b) => [$a['severity'], $a['params']['days_left'] ?? 999] <=> [$b['severity'], $b['params']['days_left'] ?? 999]);
        return array_slice($all, 0, $limit);
    }
}
