<?php

namespace App\Services\Ads;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bid floors and suggestions. All numbers come from AdSettings.
 * (The per-click second-price charge is added with the ad server.)
 */
class AdPricing
{
    public function __construct(private AdSettings $settings) {}

    /** Lowest max CPC a campaign in this category may bid: the category override or the global floor. */
    public function floorCpc(?int $categoryId): float
    {
        $global    = $this->settings->float('min_cpc');
        $overrides = (array) $this->settings->get('category_min_cpc', []);
        $category  = $categoryId !== null ? (float) ($overrides[(string) $categoryId] ?? 0) : 0.0;

        return round(max($global, $category), 3);
    }

    /**
     * Median cost of billable clicks in the category over the recent window,
     * or floor × multiplier when there's no history. Never below the floor.
     */
    public function suggestedCpc(?int $categoryId): float
    {
        $floor = $this->floorCpc($categoryId);
        $days  = max(1, $this->settings->int('suggested_cpc_window_days'));

        $median = Cache::remember("ads:suggested_cpc:{$categoryId}:{$days}", 3600, function () use ($categoryId, $days) {
            $costs = DB::table('sponsorship_events as e')
                ->join('sponsorships as s', 's.id', '=', 'e.sponsorship_id')
                ->join('products as p', 'p.id', '=', 's.product_id')
                ->where('e.event', 'click')->where('e.billable', true)->where('e.cost', '>', 0)
                ->where('e.created_at', '>=', now()->subDays($days))
                ->when($categoryId !== null, fn ($q) => $q->where('p.category_id', $categoryId))
                ->orderByDesc('e.id')->limit(5000)
                ->pluck('e.cost')->map(fn ($c) => (float) $c)->sort()->values();

            return $costs->isEmpty() ? null : (float) $costs->median();
        });

        $suggested = $median ?? $floor * $this->settings->float('suggested_cpc_multiplier');
        return round(max($floor, $suggested), 3);
    }

    /** Share taken off every click for this plan tier (free|red|black), 0..1. */
    public function tierDiscount(string $tier): float
    {
        return min(1.0, max(0.0, (float) $this->settings->get("tier_click_discount.{$tier}", 0)));
    }

    public function monthlyCredit(string $tier): float
    {
        return round(max(0.0, (float) $this->settings->get("monthly_credit.{$tier}", 0)), 3);
    }
}
