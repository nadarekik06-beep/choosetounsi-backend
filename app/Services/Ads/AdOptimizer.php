<?php

namespace App\Services\Ads;

use App\Models\Sponsorship;
use App\Notifications\Ads\CampaignLowPerformance;
use Illuminate\Support\Facades\DB;

/**
 * Daily look at each running CPC campaign (ads:optimize) so sellers get their
 * money's worth:
 *   - no_reach        no impressions after N days → bid more / widen targeting
 *   - low_ctr         CTR below half the placements' usual CTR → photos, ad line, price
 *   - low_roas        return on ad spend < 1 after enough clicks → lower CPC, improve listing, discount
 *   - placement_no_orders  a placement keeps getting clicks without orders → its weight for
 *                     this campaign drops (the ad server ranks it lower there)
 * Tips are stored on the campaign (sponsorships.optimizer) and shown in the dashboard;
 * low_ctr / low_roas also notify the seller, at most once a week each.
 */
class AdOptimizer
{
    const LOW_WEIGHT        = 0.5;
    const MIN_IMPRESSIONS   = 200;
    const NOTIFY_EVERY_DAYS = 7;

    public function __construct(private AdSettings $settings, private ReadinessService $readiness) {}

    public function run(): int
    {
        $checked = 0;
        Sponsorship::with('product', 'seller')
            ->whereIn('status', [Sponsorship::STATUS_ACTIVE, Sponsorship::STATUS_PAUSED])
            ->where('pricing_model', Sponsorship::PRICING_CPC)
            ->orderBy('id')
            ->each(function (Sponsorship $c) use (&$checked) {
                $this->optimize($c);
                $checked++;
            });
        return $checked;
    }

    public function optimize(Sponsorship $c): array
    {
        $minClicks = max(1, $this->settings->int('optimizer_min_clicks'));
        $ageDays   = $c->start_at ? $c->start_at->diffInDays(now()) : 0;
        $old       = (array) ($c->optimizer ?? []);

        $rows = DB::table('sponsorship_daily_stats')->where('sponsorship_id', $c->id)
            ->groupBy('placement')
            ->get(['placement', DB::raw('SUM(impressions) i'), DB::raw('SUM(clicks) c'), DB::raw('SUM(cost) cost'),
                   DB::raw('SUM(orders) o'), DB::raw('SUM(revenue) rev')]);
        $imp = (int) $rows->sum('i');
        $clk = (int) $rows->sum('c');
        $cost = (float) $rows->sum('cost');
        $rev = (float) $rows->sum('rev');

        $tips = [];
        $weights = [];

        if ($ageDays >= $this->settings->int('low_performance_after_days')) {
            if ($imp === 0) {
                $tips[] = ['code' => 'no_reach', 'params' => []];
            } elseif ($imp >= self::MIN_IMPRESSIONS) {
                $expected = $rows->sum(fn ($r) => (int) $r->i * (float) $this->settings->get("pctr_prior.{$r->placement}", 0.02)) / $imp;
                $ctr = $clk / $imp;
                if ($ctr < 0.5 * $expected) {
                    $tips[] = ['code' => 'low_ctr', 'params' => ['ctr' => round($ctr, 4), 'expected' => round($expected, 4)]];
                }
            }
        }

        if ($clk >= $minClicks && $cost > 0 && $rev / $cost < 1) {
            $tips[] = ['code' => 'low_roas', 'params' => ['roas' => round($rev / $cost, 2), 'spend' => round($cost, 3)]];
        }

        foreach ($rows as $r) {
            if ((int) $r->c >= $minClicks && (int) $r->o === 0) {
                $weights[$r->placement] = self::LOW_WEIGHT;
                $tips[] = ['code' => 'placement_no_orders', 'params' => ['placement' => $r->placement, 'clicks' => (int) $r->c]];
            }
        }

        // Listing fixes the readiness check still sees.
        if ($tips && $c->product) {
            foreach ($this->readiness->check($c->product)['tips'] as $tip) {
                $tips[] = ['code' => 'listing_' . $tip['code'], 'params' => $tip['params'] ?? [], 'action' => $tip['action'] ?? null];
            }
        }

        // Notify about the serious ones, at most weekly per kind.
        $notified = (array) ($old['notified'] ?? []);
        $fresh = [];
        foreach (['low_ctr', 'low_roas'] as $code) {
            if (in_array($code, array_column($tips, 'code'), true)
                && (empty($notified[$code]) || now()->diffInDays($notified[$code]) >= self::NOTIFY_EVERY_DAYS)) {
                $notified[$code] = now()->toDateString();
                $fresh[] = $code;
            }
        }

        $result = [
            'tips'              => $tips,
            'placement_weights' => $weights ?: (object) [],
            'checked_at'        => now()->toIso8601String(),
            'notified'          => $notified ?: (object) [],
        ];
        $c->forceFill(['optimizer' => $result])->save();

        if ($fresh && $c->seller) {
            $c->seller->notify(new CampaignLowPerformance($c, $fresh));
        }
        if ($weights != ($old['placement_weights'] ?? [])) {
            AdServer::flushEligible();
        }
        return $result;
    }
}
