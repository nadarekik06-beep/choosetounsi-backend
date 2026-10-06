<?php

namespace App\Services\GrowthRadar\Detectors;

use App\Services\Forecast\Calendar;
use App\Services\GrowthRadar\Benchmarks;
use App\Services\GrowthRadar\Card;
use App\Services\GrowthRadar\SellerContext;

/**
 * The next Tunisian calendar moment (admin calendar, same one the sales
 * forecast uses) that applies to the seller's categories, with the best start
 * date for a promotion on their strongest matching products. The lift is the
 * category's measured effect last time when it stands on enough orders,
 * otherwise a conservative default — and the card says which.
 */
class SeasonalDetector implements Detector
{
    use BuildsActions;

    public function __construct(private Calendar $calendar) {}

    public function detect(SellerContext $ctx, Benchmarks $bench): array
    {
        $cfg = config('growth.seasonal');
        [$minDays, $maxDays] = $cfg['horizon_days'];

        // event id => [event, category ids it applies to among the seller's]
        $events = [];
        foreach ($ctx->categoryIds() as $cat) {
            foreach ($this->calendar->forCategory($cat, $ctx->today) as $ev) {
                $until = (int) $ctx->today->diffInDays(\Carbon\CarbonImmutable::parse($ev['starts_on'], $ctx->today->tz), false);
                if ($until < $minDays || $until > $maxDays) continue;
                $events[$ev['id']] ??= ['ev' => $ev, 'until' => $until, 'cats' => []];
                $events[$ev['id']]['cats'][] = $cat;
            }
        }
        uasort($events, fn ($a, $b) => $a['until'] <=> $b['until']);

        $cards = [];
        foreach (array_slice($events, 0, (int) $cfg['max_cards'], true) as $id => $e) {
            $ev = $e['ev'];
            $products = array_filter($ctx->products, fn ($p) => in_array($p['category_id'], $e['cats'], true) && !$p['promo_running'] && $p['stock'] > 0);
            if (!$products) continue;
            uasort($products, fn ($a, $b) => [$b['cur']['units'] + $b['prev']['units'], $b['cur']['views']] <=> [$a['cur']['units'] + $a['prev']['units'], $a['cur']['views']]);
            $products = array_slice($products, 0, 3, true);
            $ids = array_keys($products);

            // Measured effect of the last occurrence in these categories (aggregate, ≥ min orders)
            $effect = null;
            foreach ($e['cats'] as $cat) {
                $m = $this->calendar->lastEffect($ev['key'], $cat, $ev['starts_on']);
                if ($m && $m['event_orders'] >= Benchmarks::floor('min_events') && ($effect === null || $m['event_orders'] > $effect['event_orders'])) {
                    $effect = $m;
                }
            }
            if ($effect && $effect['change_pct'] <= 0) continue;   // demand usually drops: nothing to push

            $lead  = (int) ($cfg['lead_days'][$ev['key']] ?? 7);
            $start = \Carbon\CarbonImmutable::parse($ev['starts_on'], $ctx->today->tz)->subDays($lead);
            $earliest = $ctx->today->addDay();
            if ($start < $earliest) $start = $earliest;
            $traffic = $bench->traffic();
            $start = $start->setTime($traffic ? $traffic['hour'] : 9, 0);

            $kind = $ctx->learning->preferredPromo();
            $days = (int) $cfg['promo_days'];
            $pct  = (int) $cfg['promo_pct'];
            $action = $kind === 'flash_sale'
                ? $this->promo('flash_sale', $ids, $pct + 5, $start->addDays(max(0, $lead - 2)), 48)
                : $this->promo('discount', $ids, $pct, $start, $days);

            // Weekly revenue of these products: own history first, else the category benchmark.
            $own = 0.0;
            foreach ($products as $p) $own += $p['cur']['net_revenue'] + $p['prev']['net_revenue'];
            $weekly = $own / (2 * config('growth.window_days') / 7);
            $basis = 'own';
            if ($weekly <= 0) {
                $perProduct = $bench->weeklyRevenuePerProduct($products[$ids[0]]['category_id']);
                $weekly = $perProduct !== null ? $perProduct * count($ids) : 0.0;
                $basis = $perProduct !== null ? 'category' : 'none';
            }
            [$lo, $hi] = $effect ? [$effect['change_pct'] / 200, $effect['change_pct'] / 100] : $cfg['default_lift'];
            $weeks = ($kind === 'flash_sale' ? 2 : $days) / 7;

            $confidence = $effect && $basis === 'own' ? 'medium' : 'low';
            $locale = app()->getLocale();
            $card = new Card('seasonal', "season:$id", $ids[0], $confidence,
                ['event' => $ev['names'][$locale] ?? $ev['names']['fr'], 'event_key' => $ev['key'], 'days_until' => $e['until'],
                 'starts_on' => $ev['starts_on'], 'start_date' => $start->toDateString(), 'pct' => $action['discount_value'],
                 'products' => array_values(array_map(fn ($p) => $p['name'], $products)), 'count' => count($ids),
                 'effect_pct' => $effect ? (int) round($effect['change_pct']) : null, 'effect_year' => $effect['year'] ?? null,
                 'kind' => $kind],
                ['numbers' => array_values(array_filter([
                    ['key' => 'days_until_event', 'value' => $e['until']],
                    $effect ? ['key' => 'last_year_lift', 'value' => (int) round($effect['change_pct']), 'unit' => '%'] : null,
                    ['key' => 'products_matched', 'value' => count($ids)],
                 ])), 'chart' => ['kind' => 'timeline', 'today' => $ctx->today->toDateString(), 'start' => $start->toDateString(),
                                  'event_start' => $ev['starts_on'], 'event_end' => $ev['ends_on']]],
                $action, null,
                ['revenue' => $basis, 'lift' => $effect ? 'measured' : 'default'],
            );
            // Names in all locales: the card is rendered later in the viewer's language.
            $card->params['event_names'] = $ev['names'];
            $cards[] = $basis === 'none'
                ? $card->impact(null, null)
                : $card->impact($weekly * $weeks * $lo, $weekly * $weeks * $hi, $ctx->learning->multiplier($kind));
        }
        return $cards;
    }
}
