<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\ProductCommissionOverride;
use App\Models\SellerSubscription;
use App\Models\SubscriptionPlan;
use App\Support\Millimes;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * CommissionService
 *
 * Single source of truth for all commission math on ChooseTounsi.
 *
 * Rate resolution, highest priority first (rateFor = product level on top of resolveForSeller):
 *   0. product_override — product_commission_overrides (admin special offer, live at the time)
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

    // ── Product-level special commission ──────────────────────────────────────

    public const SOURCE_PRODUCT_OVERRIDE = 'product_override';

    /**
     * The special commission live on a product at $at, or null. Always read
     * fresh from the DB and decided by date comparison (starts_at <= at < ends_at),
     * so it is right even when the expiry command is late.
     */
    public function activeProductOverride(?int $productId, ?CarbonInterface $at = null): ?ProductCommissionOverride
    {
        if (!$productId) {
            return null;
        }
        return ProductCommissionOverride::where('product_id', $productId)
            ->liveAt($at ?? Carbon::now())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * THE rate of the platform for one product of one seller at a unit price.
     *
     *   1. live product override (admin special offer)
     *        fixed_rate        → that % is the final rate (no seller override, no plan reduction)
     *        reduction_points  → the rate that would otherwise apply − X points, clamped to 0–100
     *                            (NOT to the floor: it is a deliberate admin decision)
     *   2. seller override → plan flat rate → bracket − plan reduction (resolveForSeller, unchanged)
     *
     * $price is the bracket price (the ORIGINAL unit price, AUDIT M-7), like resolveForSeller.
     * Without a live override the result is exactly resolveForSeller().
     *
     * @return array{rate: float, source: string, plan: string, override_id: ?int, normal_rate: float, normal_source: string}
     */
    public function rateFor(?int $productId, ?int $sellerId, float $price, ?CarbonInterface $at = null): array
    {
        $normal   = $this->resolveForSeller($sellerId, $price);
        $override = $this->activeProductOverride($productId, $at);

        if ($override === null) {
            return $normal + ['override_id' => null, 'normal_rate' => $normal['rate'], 'normal_source' => $normal['source']];
        }

        return [
            'rate'          => self::applyOverride($override->type, (float) $override->value, (float) $normal['rate']),
            'source'        => self::SOURCE_PRODUCT_OVERRIDE,
            'plan'          => $normal['plan'],
            'override_id'   => (int) $override->id,
            'normal_rate'   => $normal['rate'],
            'normal_source' => $normal['source'],
        ];
    }

    /**
     * The one formula of a special commission (checkout AND the admin preview use it):
     * fixed_rate → the value; reduction_points → normal − value; always within 0–100.
     */
    public static function applyOverride(string $type, float $value, float $normalRate): float
    {
        $rate = $type === ProductCommissionOverride::TYPE_FIXED ? $value : $normalRate - $value;
        return max(0.0, min(100.0, round($rate, 2)));
    }

    /**
     * Money breakdown of one unit at a rate chosen by the caller (admin preview of an
     * override that is not saved yet). Same breakdown() as checkout.
     */
    public function breakdownAtRate(float $rate, string $plan, float $unitPrice, int $quantity = 1, float $discount = 0.0, ?float $tierPrice = null, int $fundedM = 0): array
    {
        return $this->breakdown($unitPrice, $rate, $plan, $quantity, $discount, $tierPrice, $fundedM);
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
     *
     * Rule for every discount (promotion, flash sale, pack, coupon — AUDIT M-7):
     * the bracket is chosen from the ORIGINAL unit price ($tierPrice; defaults to
     * $unitPrice), the rate is applied to the FINAL amount paid (line − coupon).
     *
     * $fundedM (AUDIT M-8): discount the platform pays back to the seller on a
     * platform-funded promotion. The seller is paid as if sold at the original
     * price (original − commission on original); `commission_amount` is what the
     * platform keeps from the cash (final − seller), negative when the platform
     * pays more than it earns. Always: net_total = seller_amount + commission_amount.
     */
    public function calculateForSeller(?int $sellerId, float $unitPrice, int $quantity = 1, float $discount = 0.0, ?float $tierPrice = null, int $fundedM = 0, ?int $productId = null, ?CarbonInterface $at = null): array
    {
        $tier     = $tierPrice ?? $unitPrice;
        $resolved = $this->rateFor($productId, $sellerId, $tier, $at);
        return $this->breakdown($unitPrice, $resolved['rate'], $resolved['plan'], $quantity, $discount, $tier, $fundedM)
            + [
                'commission_source'      => $resolved['source'],
                'commission_override_id' => $resolved['override_id'],
                // What the rate would have been without the special offer (= the rate when there is none)
                'normal_rate'            => $resolved['normal_rate'],
                'normal_source'          => $resolved['normal_source'],
            ];
    }

    /**
     * Full breakdown for a plan (no seller override) — previews and marketing.
     * Same rule as calculateForSeller(): bracket from $tierPrice (original), rate on the final amount.
     */
    public function calculate(float $unitPrice, string $plan, int $quantity = 1, float $discount = 0.0, ?float $tierPrice = null): array
    {
        $tier     = $tierPrice ?? $unitPrice;
        $resolved = $this->planRate($tier, SubscriptionPlan::forSlug($plan));
        return $this->breakdown($unitPrice, $resolved['rate'], $plan, $quantity, $discount, $tier)
            + ['commission_source' => $resolved['source']];
    }

    /**
     * Integer millimes throughout (App\Support\Millimes): the line's commission
     * is rounded half-up to the millime, once. The *_m keys carry the exact
     * millimes; the float keys are the same values for existing callers.
     */
    private function breakdown(float $unitPrice, float $rate, string $plan, int $quantity, float $discount, ?float $tierPrice = null, int $fundedM = 0): array
    {
        $tier        = $tierPrice ?? $unitPrice;
        $unitM       = Millimes::of($unitPrice);
        $totalM      = $unitM * $quantity;
        $discountM   = min(max(Millimes::of($discount), 0), $totalM);
        $netM        = $totalM - $discountM;
        $fundedM     = max(0, $fundedM);
        $baseM       = $netM + $fundedM;                     // = original amount on a platform-funded line
        $sellerM     = $baseM - Millimes::percent($baseM, $rate);
        $commissionM = $netM - $sellerM;                     // what the platform keeps from the cash

        // How much the seller saves vs the default plan (for upgrade nudge)
        $defaultRate = $this->planRate($tier, SubscriptionPlan::defaultPlan())['rate'];
        $savedM      = Millimes::percent($baseM, $defaultRate) - Millimes::percent($baseM, $rate);

        return [
            'unit_price'             => Millimes::toFloat($unitM),
            'quantity'               => $quantity,
            'total_price'            => Millimes::toFloat($totalM),
            'discount_amount'        => Millimes::toFloat($discountM),
            'net_total'              => Millimes::toFloat($netM),
            'commission_percentage'  => $rate,
            'commission_amount'      => Millimes::toFloat($commissionM),
            'seller_amount'          => Millimes::toFloat($sellerM),
            'plan_used'              => $plan,
            'base_rate'              => $this->getBaseRate($tier),
            'plan_reduction'         => $this->getPlanReduction($plan),
            'saved_with_plan'        => Millimes::toFloat(max(0, $savedM)),
            'tier_price'             => round($tier, 3),
            'total_price_m'          => $totalM,
            'discount_amount_m'      => $discountM,
            'net_total_m'            => $netM,
            'commission_amount_m'    => $commissionM,
            'seller_amount_m'        => $sellerM,
            'platform_funded_m'      => $fundedM,
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
        return $this->rateRange($p->commission_rate !== null ? (float) $p->commission_rate : null, (float) $p->commission_reduction);
    }

    /**
     * Range for a plan described by its settings: a flat rate, or the platform tiers
     * minus a reduction (floored). The admin plan editor previews unsaved values with it.
     *
     * @return array{min: float, max: float}
     */
    public function rateRange(?float $flatRate, float $reduction): array
    {
        if ($flatRate !== null) {
            return ['min' => $flatRate, 'max' => $flatRate];
        }
        $rates = array_map(
            fn(array $t) => max((float) $t['rate'] - $reduction, $this->floor()),
            $this->defaultTable()['tiers']
        );
        return ['min' => (float) min($rates), 'max' => (float) max($rates)];
    }

    /**
     * "Upgrade and save" estimate for the seller dashboard, from the seller's REAL last-30-day
     * lines (their stored tier price and amount) priced under the current and the next plan
     * with the live commission grid: no hard-coded rates anywhere. Null when there is no
     * higher plan or nothing sold.
     */
    public function upgradeNudge(int $sellerId): ?array
    {
        $currentSlug = $this->resolveForSeller($sellerId, 0.0)['plan'];
        $current     = SubscriptionPlan::forSlug($currentSlug);
        $next        = SubscriptionPlan::offered()->ordered()->get()->first(fn ($p) => $p->isHigherThan($current));
        if (!$next) {
            return null;
        }

        $lines = \Illuminate\Support\Facades\DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->where('so.seller_id', $sellerId)
            ->where('so.created_at', '>=', Carbon::now()->subDays(30))
            ->whereNotIn('so.status', \App\Models\SellerOrder::NOT_SHIPPED)
            ->get(['oi.net_total', 'oi.total', 'oi.discount_amount', 'oi.commission_tier_price', 'oi.original_unit_price', 'oi.unit_price', 'oi.quantity']);

        $revenueM = $currentFeesM = $nextFeesM = 0;
        foreach ($lines as $l) {
            $netM = Millimes::of($l->net_total ?? ((float) $l->total - (float) ($l->discount_amount ?? 0)));
            if ($netM <= 0) {
                continue;
            }
            $tier = (float) ($l->commission_tier_price ?? $l->original_unit_price ?? $l->unit_price);
            $revenueM     += $netM;
            $currentFeesM += Millimes::percent($netM, $this->planRate($tier, $current)['rate']);
            $nextFeesM    += Millimes::percent($netM, $this->planRate($tier, $next)['rate']);
        }
        if ($revenueM <= 0) {
            return null;
        }

        $savedM = max(0, $currentFeesM - $nextFeesM);
        return [
            'current_plan'    => $current->slug,
            'next_plan'       => ['key' => $next->slug, 'name' => $next->name, 'cost' => (float) $next->price_monthly],
            'monthly_revenue' => Millimes::toFloat($revenueM),
            'current_fees'    => Millimes::toFloat($currentFeesM),
            'next_fees'       => Millimes::toFloat($nextFeesM),
            'monthly_saved'   => Millimes::toFloat($savedM),
            'net_gain'        => round(Millimes::toFloat($savedM) - (float) $next->price_monthly, 3),
        ];
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
