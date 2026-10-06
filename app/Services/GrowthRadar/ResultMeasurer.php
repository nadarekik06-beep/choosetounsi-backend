<?php

namespace App\Services\GrowthRadar;

use App\Models\User;
use App\Notifications\Growth\GrowthRadarNotification;
use App\Services\Forecast\SalesSeries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Measures an applied action once it has ended (+ config('growth.results.after_days')).
 *
 *  - during   = the action's own days (L days, at most results.max_window)
 *  - baseline = the same number of days just before it
 *  - after    = up to L days after it ended (capped by after_days)
 *
 * Numbers come from the forecast's daily series (real sales only, net of
 * discounts and returns). Money: extra revenue = gross revenue during the
 * action (net + discounts given) − baseline revenue; net gain = net revenue
 * during − baseline revenue − ad spend.
 *
 * We never claim a win we can't prove. The result is "unclear" when the product
 * was listed too recently for a full baseline, another promotion / boost ran in
 * the baseline, or there are too few sales to tell. Otherwise a one-sided test
 * on the unit counts (z ≥ 1.64): "win" needs a real rise in units AND a positive
 * net gain, "loss" a real drop or a negative net, else "neutral".
 */
class ResultMeasurer
{
    public function __construct(private SalesSeries $series) {}

    /** Actions due for measurement. */
    public function due(?CarbonImmutable $now = null): \Illuminate\Support\Collection
    {
        $now ??= CarbonImmutable::now();
        return DB::table('growth_actions')->where('status', 'running')
            ->where('ends_at', '<=', $now->subDays((int) config('growth.results.after_days'))->utc())
            ->orderBy('id')->get();
    }

    public function measure(object $action, bool $notify = true): array
    {
        $tz = config('growth.timezone');
        $cfg = config('growth.results');

        $start = CarbonImmutable::parse($action->starts_at, 'UTC')->tz($tz)->startOfDay();
        $endAt = CarbonImmutable::parse($action->ends_at, 'UTC')->tz($tz);
        // Every calendar day the action was live counts (a 7-day discount from 09:00 touches 8 days)
        $lastDay = $endAt->startOfDay()->equalTo($endAt) ? $endAt->subDay()->startOfDay() : $endAt->startOfDay();
        $len = (int) max(1, min((int) $cfg['max_window'], (int) $start->diffInDays($lastDay) + 1));
        $during = [$start, $start->addDays($len)];
        $baseline = [$start->subDays($len), $start];
        $afterLen = min($len, (int) $cfg['after_days']);
        $after = [$during[1], $during[1]->addDays($afterLen)];

        $unclear = [];
        $result = null;

        if (!$action->product_id || $action->kind === 'listing') {
            $unclear[] = 'not_measurable';
        } else {
            $this->series->rebuild((int) $action->seller_id);
            $product = $this->series->load((int) $action->seller_id)[(int) $action->product_id] ?? null;
            if (!$product) {
                $unclear[] = 'not_measurable';
            } else {
                $daily = $product['daily'];
                $b = $this->window($daily, ...$baseline);
                $d = $this->window($daily, ...$during);
                $a = $this->window($daily, ...$after);

                if ($product['listed_since'] > $baseline[0]->toDateString()) $unclear[] = 'short_history';
                if ($b['promo_days'] > 0) $unclear[] = 'overlap';
                if ($b['units'] + $d['units'] < (int) $cfg['min_units']) $unclear[] = 'few_sales';

                $discount = $this->discountCost($action, $during);
                $adSpend  = $this->adSpend($action, $during);
                $gross = $d['revenue'] + $discount - $b['revenue'];
                $net   = $d['revenue'] - $b['revenue'] - $adSpend;

                $days = [];
                foreach ([['before', $baseline], ['during', $during], ['after', $after]] as [$phase, [$from, $to]]) {
                    for ($day = $from; $day < $to; $day = $day->addDay()) {
                        $days[] = ['day' => $day->toDateString(), 'units' => (int) ($daily[$day->toDateString()]['units'] ?? 0), 'phase' => $phase];
                    }
                }

                $result = [
                    'days'          => $len,
                    'baseline'      => self::metrics($b),
                    'during'        => self::metrics($d),
                    'after'         => $afterLen > 0 ? self::metrics($a) : null,
                    'daily'         => $days,
                    'discount_cost' => round($discount, 3),
                    'ad_spend'      => round($adSpend, 3),
                    'gross_gain'    => round($gross, 3),
                    'net_gain'      => round($net, 3),
                    'lift_pct'      => $b['units'] > 0 ? round(($d['units'] - $b['units']) / $b['units'] * 100, 1) : null,
                    'z'             => $b['units'] + $d['units'] > 0 ? round(($d['units'] - $b['units']) / sqrt($b['units'] + $d['units']), 2) : 0.0,
                ];
            }
        }

        $verdict = 'unclear';
        if (!$unclear && $result) {
            $z = $result['z'];
            $verdict = match (true) {
                $z >= $cfg['z'] && $result['net_gain'] > 0 => 'win',
                $z <= -$cfg['z'] || ($result['net_gain'] < 0 && $z >= $cfg['z']) => 'loss',   // fewer sales, or more sales that cost more than they brought
                default => 'neutral',
            };
        }
        $result = ($result ?? []) + ['unclear' => $unclear];

        DB::table('growth_actions')->where('id', $action->id)->update([
            'status' => 'measured', 'verdict' => $verdict, 'result' => json_encode($result),
            'measured_at' => now(), 'updated_at' => now(),
        ]);

        if ($notify && app(GrowthRadar::class)->hasFullFeed((int) $action->seller_id)) {
            try {
                User::find($action->seller_id)?->notify(new GrowthRadarNotification('result', [
                    'kind' => $action->kind, 'verdict' => $verdict,
                    'product' => $action->product_id ? (string) DB::table('products')->where('id', $action->product_id)->value('name') : '',
                ], false));
            } catch (\Throwable $e) {
                Log::warning('[GrowthRadar] Result notification failed: ' . $e->getMessage());
            }
        }
        return ['verdict' => $verdict] + $result;
    }

    private function window(array $daily, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $s = SellerContext::sum($daily, $from, $to);
        return $s + ['revenue' => $s['net_revenue']];
    }

    private static function metrics(array $w): array
    {
        return ['units' => (int) $w['units'], 'revenue' => round((float) $w['revenue'], 3), 'views' => (int) $w['views'], 'orders' => (int) $w['orders']];
    }

    /** Discounts given through this action on the measured product (promotions, coupons). */
    private function discountCost(object $action, array $window): float
    {
        [$from, $to] = array_map(fn ($d) => $d->utc(), $window);
        if (in_array($action->kind, ['discount', 'flash_sale'], true) && $action->ref_id) {
            return (float) DB::table('order_items as oi')->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
                ->where('oi.promotion_id', $action->ref_id)->where('oi.product_id', $action->product_id)
                ->whereIn('so.status', config('forecast.sale_statuses'))
                ->whereBetween('so.created_at', [$from, $to])->sum('oi.discount_amount');
        }
        if ($action->kind === 'coupon' && $action->ref_id) {
            return (float) DB::table('coupon_redemptions as cr')->join('seller_orders as so', 'so.id', '=', 'cr.seller_order_id')
                ->where('cr.coupon_id', $action->ref_id)->whereIn('so.status', config('forecast.sale_statuses'))
                ->whereBetween('cr.created_at', [$from, $to])->sum('cr.discount_amount');
        }
        return 0.0;
    }

    private function adSpend(object $action, array $window): float
    {
        if ($action->kind !== 'boost' || !$action->ref_id) return 0.0;
        return (float) DB::table('sponsorship_daily_stats')->where('sponsorship_id', $action->ref_id)
            ->whereBetween('date', [$window[0]->toDateString(), $window[1]->subDay()->toDateString()])->sum('cost');
    }
}
