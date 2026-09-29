<?php

namespace App\Services\Ads;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * "What will this budget buy?" — daily impression / click / order ranges for a
 * campaign, shown in the wizard before the seller commits.
 *
 * Source, in order: the category's own campaign history (sponsorship_daily_stats,
 * last 14 days), else category traffic from user_interactions × the reachable
 * share, with CTR from the placement priors and CVR from the category's
 * purchases per view. Clicks are capped by what the daily budget can pay for.
 */
class AdForecastService
{
    const WINDOW_DAYS = 14;
    const RANGE_LOW   = 0.6;
    const RANGE_HIGH  = 1.4;

    public function __construct(private AdSettings $settings, private AdPricing $pricing) {}

    public function forecast(Product $product, float $dailyBudget, float $maxCpc, ?int $days, string $tier): array
    {
        $floor = $this->pricing->floorCpc($product->category_id);
        $bid   = max($floor, $maxCpc);
        // A click usually clears below the bid (second price): expect the lower of bid and market.
        $cpc   = round(min($bid, $this->pricing->suggestedCpc($product->category_id)) * (1 - $this->pricing->tierDiscount($tier)), 3);
        $cpc   = max(0.001, $cpc);

        [$impressions, $ctr, $cvr, $source] = $this->history($product->category_id) ?? $this->traffic($product->category_id);

        $trafficClicks = $impressions * $ctr;
        $budgetClicks  = $dailyBudget / $cpc;
        $clicks        = min($trafficClicks, $budgetClicks);
        $orders        = $clicks * $cvr;
        $spend         = $clicks * $cpc;

        $daily = [
            'impressions' => $this->range($impressions, 0),
            'clicks'      => $this->range($clicks, 0),
            'orders'      => $this->range($orders, 1),
            'spend'       => $this->range($spend, 3, $dailyBudget),
        ];

        return [
            'daily'          => $daily,
            'total'          => $days ? array_map(fn ($r) => [round($r[0] * $days, 3), round($r[1] * $days, 3)], $daily) : null,
            'days'           => $days,
            'budget_limited' => $budgetClicks < $trafficClicks,
            'assumptions'    => [
                'expected_cpc' => $cpc,
                'ctr'          => round($ctr, 4),
                'cvr'          => round($cvr, 4),
                'source'       => $source,
            ],
        ];
    }

    /** [daily impressions, ctr, cvr, 'history'] from past campaigns in the category, or null. */
    private function history(?int $categoryId): ?array
    {
        $row = DB::table('sponsorship_daily_stats as d')
            ->join('sponsorships as s', 's.id', '=', 'd.sponsorship_id')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('d.date', '>=', AdClock::now()->subDays(self::WINDOW_DAYS)->toDateString())
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->selectRaw('SUM(d.impressions) AS impressions, SUM(d.clicks) AS clicks, SUM(d.orders) AS orders,
                         COUNT(DISTINCT d.sponsorship_id, d.date) AS campaign_days')
            ->first();

        if (!$row || (int) $row->impressions < 100 || (int) $row->campaign_days === 0) {
            return null;
        }

        $ctr = (int) $row->clicks / (int) $row->impressions;
        $cvr = (int) $row->clicks > 0 ? (int) $row->orders / (int) $row->clicks : $this->settings->float('forecast_default_cvr');

        return [(int) $row->impressions / (int) $row->campaign_days, $ctr, $cvr, 'history'];
    }

    /** [daily impressions, ctr, cvr, 'traffic'] from category browsing in user_interactions. */
    private function traffic(?int $categoryId): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $stats = DB::table('user_interactions')
            ->where('created_at', '>=', $since)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->selectRaw("SUM(event_type = 'view') AS views, SUM(event_type = 'purchase') AS purchases,
                         COUNT(DISTINCT CASE WHEN event_type = 'view' THEN COALESCE(user_id, session_id) END) AS viewers")
            ->first();

        $viewers   = (int) ($stats->viewers ?? 0);
        $views     = (int) ($stats->views ?? 0);
        $purchases = (int) ($stats->purchases ?? 0);

        $impressions = $viewers / self::WINDOW_DAYS * $this->settings->float('forecast_reach_share');
        $priors      = (array) $this->settings->get('pctr_prior', []);
        $ctr         = $priors ? array_sum($priors) / count($priors) : 0.02;
        $cvr         = $views >= 50 && $purchases > 0 ? $purchases / $views : $this->settings->float('forecast_default_cvr');

        return [$impressions, $ctr, $cvr, 'traffic'];
    }

    private function range(float $value, int $decimals, ?float $cap = null): array
    {
        $low  = round($value * self::RANGE_LOW, $decimals);
        $high = round($value * self::RANGE_HIGH, $decimals);
        if ($cap !== null) {
            $high = min($high, round($cap, $decimals));
            $low  = min($low, $high);
        }
        return [$low, $high];
    }
}
