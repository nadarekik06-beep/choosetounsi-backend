<?php

namespace App\Services;

/**
 * CommissionService
 *
 * Single source of truth for all commission math on ChooseTounsi.
 * Used in:
 *   - CheckoutController   → FINAL stored calculation (order_items columns)
 *   - CommissionController → live preview for seller dashboard
 *
 * NEVER recalculate from order_items — always read stored values.
 *
 * Tiers (price-based):
 *   0   – 100  DT → 15%
 *   101 – 200  DT → 12%
 *   201 – 300  DT → 10%
 *   301 – 500  DT →  8%
 *   501 – 1000 DT →  5%
 *   1000+      DT →  3%
 *
 * Plan reductions:
 *   free  (Green)  → 0%
 *   red   (Red)    → −4%
 *   black (Black)  → −8%
 *
 * Final = max(base − reduction, MIN_COMMISSION)
 */
class CommissionService
{
    // ── Commission tiers ──────────────────────────────────────────────────────
    // [min_price, max_price, base_pct]
    private const TIERS = [
        [0,       100,          15],
        [100.01,  200,          12],
        [200.01,  300,          10],
        [300.01,  500,           8],
        [500.01,  1000,          5],
        [1000.01, PHP_FLOAT_MAX, 3],
    ];

    // ── Plan reductions (percentage points knocked off base rate) ─────────────
    private const PLAN_REDUCTION = [
        'free'  => 0,
        'red'   => 3,
        'black' => 6,
    ];

    // ── Floor — never go below this regardless of plan ────────────────────────
    private const MIN_COMMISSION = 3.0;

    // ── Public: Base rate for a given unit price ──────────────────────────────

    public function getBaseRate(float $price): float
    {
        foreach (self::TIERS as [$min, $max, $rate]) {
            if ($price >= $min && $price <= $max) {
                return (float) $rate;
            }
        }
        return (float) self::TIERS[count(self::TIERS) - 1][2];
    }

    // ── Public: How many percentage points the plan reduces ───────────────────

    public function getPlanReduction(string $plan): float
    {
        return (float) (self::PLAN_REDUCTION[$plan] ?? 0);
    }

    // ── Public: Final effective commission rate ───────────────────────────────

    public function getFinalRate(float $price, string $plan): float
    {
        $base      = $this->getBaseRate($price);
        $reduction = $this->getPlanReduction($plan);
        return max($base - $reduction, self::MIN_COMMISSION);
    }

    // ── Public: Full breakdown — PRIMARY method ───────────────────────────────
    //
    // @param float  $unitPrice  Product/variant selling price
    // @param string $plan       Seller's active plan ('free'|'red'|'black')
    // @param int    $quantity   Units in this line item (default: 1)
    //
    // @return array {
    //   unit_price, quantity, total_price,
    //   commission_percentage, commission_amount, seller_amount,
    //   plan_used, base_rate, plan_reduction, saved_with_plan
    // }

    public function calculate(float $unitPrice, string $plan, int $quantity = 1): array
    {
        $finalRate        = $this->getFinalRate($unitPrice, $plan);
        $totalPrice       = round($unitPrice * $quantity, 3);
        $commissionAmount = round($totalPrice * ($finalRate / 100), 3);
        $sellerAmount     = round($totalPrice - $commissionAmount, 3);

        // How much the seller saves vs the free plan (for upgrade nudge)
        $freeRate         = $this->getFinalRate($unitPrice, 'free');
        $freeCommission   = round($totalPrice * ($freeRate / 100), 3);
        $savedWithPlan    = round($freeCommission - $commissionAmount, 3);

        return [
            'unit_price'             => $unitPrice,
            'quantity'               => $quantity,
            'total_price'            => $totalPrice,
            'commission_percentage'  => $finalRate,
            'commission_amount'      => $commissionAmount,
            'seller_amount'          => $sellerAmount,
            'plan_used'              => $plan,
            'base_rate'              => $this->getBaseRate($unitPrice),
            'plan_reduction'         => $this->getPlanReduction($plan),
            'saved_with_plan'        => max(0, $savedWithPlan),
        ];
    }

    // ── Public: What each upgrade plan would save per this line item ──────────
    //
    // Used by CommissionController to show upgrade suggestions.
    //
    // @return array[]  Each element: { plan, plan_name, monthly_cost,
    //                                   saved_per_sale, new_rate, new_seller_amount }

    public function getUpgradeSavings(float $unitPrice, string $currentPlan, int $quantity = 1): array
    {
        $planHierarchy = ['free' => 0, 'red' => 1, 'black' => 2];
        $currentLevel  = $planHierarchy[$currentPlan] ?? 0;

        $upgrades = [
            'red'   => ['name' => 'Red Pepper',  'monthly' => 49],
            'black' => ['name' => 'Black Pepper', 'monthly' => 129],
        ];

        $suggestions = [];
        $current     = $this->calculate($unitPrice, $currentPlan, $quantity);

        foreach ($upgrades as $plan => $info) {
            if (($planHierarchy[$plan] ?? 0) <= $currentLevel) continue;

            $upgraded     = $this->calculate($unitPrice, $plan, $quantity);
            $savedPerSale = round($current['commission_amount'] - $upgraded['commission_amount'], 3);

            if ($savedPerSale <= 0) continue;

            $suggestions[] = [
                'plan'              => $plan,
                'plan_name'         => $info['name'],
                'monthly_cost'      => $info['monthly'],
                'saved_per_sale'    => $savedPerSale,
                'new_rate'          => $upgraded['commission_percentage'],
                'new_seller_amount' => $upgraded['seller_amount'],
                'message'           => "Upgrade to {$info['name']} → save {$savedPerSale} TND per sale",
            ];
        }

        return $suggestions;
    }
}