<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\SellerSubscription;
use App\Models\SubscriptionPlan;

/**
 * CommissionService
 *
 * Single source of truth for all commission math on ChooseTounsi.
 *
 * Rate resolution (resolveForSeller), highest priority first:
 *   1. override — seller_subscriptions.commission_override (while not expired)
 *   2. plan     — subscription_plans.commission_rate (flat %), or the platform
 *                 tier table minus subscription_plans.commission_reduction
 *   3. default  — platform tier table (platform_settings 'commission.default')
 *
 * Used in:
 *   - CheckoutController   → FINAL stored calculation (order_items + seller_orders snapshot)
 *   - CommissionController → live preview for seller dashboard
 *   - Admin views          → "effective rate" display
 *
 * NEVER recalculate from order_items — always read stored values. Changing a
 * plan / override / default only affects orders placed afterwards.
 */
class CommissionService
{
    public const SETTING_KEY = 'commission.default';

    /** Fallback if the platform setting row is missing. */
    private const FALLBACK = [
        'tiers' => [
            ['min' => 0,       'max' => 100,  'rate' => 15],
            ['min' => 100.01,  'max' => 200,  'rate' => 12],
            ['min' => 200.01,  'max' => 300,  'rate' => 10],
            ['min' => 300.01,  'max' => 500,  'rate' => 8],
            ['min' => 500.01,  'max' => 1000, 'rate' => 5],
            ['min' => 1000.01, 'max' => null, 'rate' => 3],
        ],
        'floor' => 3,
    ];

    // ── Platform default table ────────────────────────────────────────────────

    public function defaultTable(): array
    {
        $value = PlatformSetting::getValue(self::SETTING_KEY);
        return is_array($value) && !empty($value['tiers']) ? $value : self::FALLBACK;
    }

    public function getBaseRate(float $price): float
    {
        $tiers = $this->defaultTable()['tiers'];
        foreach ($tiers as $t) {
            $max = $t['max'] ?? null;
            if ($price >= (float) $t['min'] && ($max === null || $price <= (float) $max)) {
                return (float) $t['rate'];
            }
        }
        // Gaps between tiers (e.g. 100.005) → the closest lower tier
        $lower = array_filter($tiers, fn($t) => $price >= (float) $t['min']);
        return (float) (end($lower)['rate'] ?? end($tiers)['rate']);
    }

    public function floor(): float
    {
        return (float) ($this->defaultTable()['floor'] ?? 0);
    }

    // ── Plan-level rates ──────────────────────────────────────────────────────

    public function getPlanReduction(string $plan): float
    {
        return (float) SubscriptionPlan::forSlug($plan)->commission_reduction;
    }

    /** Rate a plan pays at a given price (ignores seller overrides). */
    public function getFinalRate(float $price, string $plan): float
    {
        return $this->planRate($price, SubscriptionPlan::forSlug($plan))['rate'];
    }

    /** @return array{rate: float, source: string} */
    private function planRate(float $price, SubscriptionPlan $plan): array
    {
        if ($plan->commission_rate !== null) {
            return ['rate' => (float) $plan->commission_rate, 'source' => 'plan'];
        }
        $reduction = (float) $plan->commission_reduction;
        $rate      = max($this->getBaseRate($price) - $reduction, $this->floor());
        return ['rate' => $rate, 'source' => $reduction > 0 ? 'plan' : 'default'];
    }

    // ── Seller-level resolution ───────────────────────────────────────────────

    /**
     * Always read fresh — this service lives inside controllers that Laravel
     * reuses within a process (queue workers, tests), and a stale override or
     * plan here would be snapshotted onto real orders.
     */
    private function subscriptionFor(?int $sellerId): ?SellerSubscription
    {
        return $sellerId ? SellerSubscription::where('user_id', $sellerId)->first() : null;
    }

    /**
     * Resolve the rate for a seller at a unit price.
     *
     * @return array{rate: float, source: 'override'|'plan'|'default', plan: string}
     */
    public function resolveForSeller(?int $sellerId, float $price): array
    {
        $sub  = $this->subscriptionFor($sellerId);
        $plan = $sub
            ? SubscriptionPlan::forSlug($sub->current_plan)
            : ($sellerId
                ? SubscriptionPlan::forSlug(optional(\App\Models\SellerApplication::where('user_id', $sellerId)->first())->plan)
                : SubscriptionPlan::defaultPlan());

        if ($sub && ($override = $sub->activeCommissionOverride()) !== null) {
            return ['rate' => $override, 'source' => 'override', 'plan' => $plan->slug];
        }

        return $this->planRate($price, $plan) + ['plan' => $plan->slug];
    }

    /**
     * Human-readable summary of what a seller currently pays (admin UI).
     */
    public function effectiveSummary(SellerSubscription $sub): array
    {
        $plan     = SubscriptionPlan::forSlug($sub->current_plan);
        $override = $sub->activeCommissionOverride();

        if ($override !== null) {
            return [
                'source'     => 'override',
                'rate'       => $override,
                'label'      => rtrim(rtrim(number_format($override, 2), '0'), '.') . '% (seller override)',
                'expires_at' => optional($sub->commission_override_expires_at)->toISOString(),
            ];
        }
        if ($plan->commission_rate !== null) {
            return [
                'source' => 'plan',
                'rate'   => (float) $plan->commission_rate,
                'label'  => rtrim(rtrim(number_format($plan->commission_rate, 2), '0'), '.') . "% ({$plan->name} flat rate)",
            ];
        }
        $range = $this->rateRangeForPlan($plan->slug);
        $reduction = (float) $plan->commission_reduction;
        return [
            'source' => $reduction > 0 ? 'plan' : 'default',
            'rate'   => null,
            'range'  => $range,
            'label'  => "{$range['min']}–{$range['max']}% by price" . ($reduction > 0 ? " ({$plan->name}: −{$reduction} pts)" : ' (platform default)'),
        ];
    }

    // ── Calculation ───────────────────────────────────────────────────────────

    /**
     * Full breakdown for a seller — resolves override / plan / default.
     * Used by checkout; the returned rate + source are snapshotted on the order.
     */
    public function calculateForSeller(?int $sellerId, float $unitPrice, int $quantity = 1, float $discount = 0.0): array
    {
        $resolved = $this->resolveForSeller($sellerId, $unitPrice);
        return $this->breakdown($unitPrice, $resolved['rate'], $resolved['plan'], $quantity, $discount)
            + ['commission_source' => $resolved['source']];
    }

    /**
     * Full breakdown for a plan (no seller override) — previews and marketing.
     *
     * Coupon rule: the rate is picked from the ORIGINAL unit price (a discount
     * can never move an item into another tier), but it is applied to the line
     * total AFTER the discount. The seller funds the discount.
     */
    public function calculate(float $unitPrice, string $plan, int $quantity = 1, float $discount = 0.0): array
    {
        $resolved = $this->planRate($unitPrice, SubscriptionPlan::forSlug($plan));
        return $this->breakdown($unitPrice, $resolved['rate'], $plan, $quantity, $discount)
            + ['commission_source' => $resolved['source']];
    }

    private function breakdown(float $unitPrice, float $rate, string $plan, int $quantity, float $discount): array
    {
        $totalPrice       = round($unitPrice * $quantity, 3);
        $discount         = round(min(max($discount, 0), $totalPrice), 3);
        $netTotal         = round($totalPrice - $discount, 3);
        $commissionAmount = round($netTotal * ($rate / 100), 3);
        $sellerAmount     = round($netTotal - $commissionAmount, 3);

        // How much the seller saves vs the default plan (for upgrade nudge)
        $defaultRate    = $this->planRate($unitPrice, SubscriptionPlan::defaultPlan())['rate'];
        $savedWithPlan  = round($netTotal * ($defaultRate / 100) - $commissionAmount, 3);

        return [
            'unit_price'             => $unitPrice,
            'quantity'               => $quantity,
            'total_price'            => $totalPrice,
            'discount_amount'        => $discount,
            'net_total'              => $netTotal,
            'commission_percentage'  => $rate,
            'commission_amount'      => $commissionAmount,
            'seller_amount'          => $sellerAmount,
            'plan_used'              => $plan,
            'base_rate'              => $this->getBaseRate($unitPrice),
            'plan_reduction'         => $this->getPlanReduction($plan),
            'saved_with_plan'        => max(0, $savedWithPlan),
        ];
    }

    // ── Display helpers ───────────────────────────────────────────────────────

    /**
     * Price tiers, plan reductions and floor, for display.
     *
     * @return array{tiers: array<array{min: float, max: ?float, rate: float}>, reductions: array<string, float>, floor: float}
     */
    public function rateTable(): array
    {
        $table = $this->defaultTable();
        return [
            'tiers' => array_map(fn(array $t) => [
                'min'  => (float) $t['min'],
                'max'  => isset($t['max']) ? (float) $t['max'] : null,
                'rate' => (float) $t['rate'],
            ], $table['tiers']),
            'reductions' => SubscriptionPlan::notArchived()->ordered()->get()
                ->mapWithKeys(fn($p) => [$p->slug => (float) $p->commission_reduction])->all(),
            'floor' => (float) ($table['floor'] ?? 0),
        ];
    }

    /** Lowest / highest rate a plan can pay. @return array{min: float, max: float} */
    public function rateRangeForPlan(string $plan): array
    {
        $p = SubscriptionPlan::forSlug($plan);
        if ($p->commission_rate !== null) {
            return ['min' => (float) $p->commission_rate, 'max' => (float) $p->commission_rate];
        }
        $rates = array_map(
            fn(array $t) => max((float) $t['rate'] - (float) $p->commission_reduction, $this->floor()),
            $this->defaultTable()['tiers']
        );
        return ['min' => (float) min($rates), 'max' => (float) max($rates)];
    }

    /**
     * What each higher plan would save per this line item.
     *
     * @return array[] { plan, plan_name, monthly_cost, saved_per_sale, new_rate, new_seller_amount, message }
     */
    public function getUpgradeSavings(float $unitPrice, string $currentPlan, int $quantity = 1): array
    {
        $current     = SubscriptionPlan::forSlug($currentPlan);
        $currentCalc = $this->calculate($unitPrice, $currentPlan, $quantity);
        $suggestions = [];

        foreach (SubscriptionPlan::offered()->ordered()->get() as $plan) {
            if (!$plan->isHigherThan($current)) continue;

            $upgraded     = $this->calculate($unitPrice, $plan->slug, $quantity);
            $savedPerSale = round($currentCalc['commission_amount'] - $upgraded['commission_amount'], 3);
            if ($savedPerSale <= 0) continue;

            $suggestions[] = [
                'plan'              => $plan->slug,
                'plan_name'         => $plan->name,
                'monthly_cost'      => (float) $plan->price_monthly,
                'saved_per_sale'    => $savedPerSale,
                'new_rate'          => $upgraded['commission_percentage'],
                'new_seller_amount' => $upgraded['seller_amount'],
                'message'           => "Upgrade to {$plan->name} → save {$savedPerSale} TND per sale",
            ];
        }

        return $suggestions;
    }
}
