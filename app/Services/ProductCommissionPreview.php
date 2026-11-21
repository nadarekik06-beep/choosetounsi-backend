<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCommissionOverride;
use App\Models\SubscriptionPlan;
use App\Support\Millimes;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Backend-computed commission figures for every preview screen (seller product /
 * promotion forms, admin "add product", admin review, the Special Commissions screen).
 * The frontends only DISPLAY these numbers: they never calculate commission.
 *
 * Every figure goes through CommissionService (the same calls as checkout), on the
 * same base: bracket from the ORIGINAL unit price, rate on the FINAL price.
 */
class ProductCommissionPreview
{
    public function __construct(private CommissionService $commission, private PromotionService $promo) {}

    /**
     * Form preview: what a seller of $sellerId earns on a unit at $price. With a $productId,
     * a live special commission on it applies (and editing the price re-prices it correctly).
     *
     * @return array the CommissionService breakdown + special_offer block (never the admin note)
     */
    public function forPrice(?int $sellerId, ?int $productId, float $price, ?float $tier = null, int $quantity = 1, int $fundedM = 0, ?CarbonInterface $at = null): array
    {
        $at   = $at ?? Carbon::now();
        $calc = $this->commission->calculateForSeller($sellerId, $price, $quantity, 0.0, $tier, $fundedM, $productId, $at);

        return $calc + ['special_offer' => $this->offerBlock($productId, $at, $calc)];
    }

    /**
     * Admin screen: one product, with an optional (unsaved) special commission.
     * "current" is what applies right now, "normal" is what would apply without any
     * product special commission, "proposed" is the new rate and its money.
     */
    public function forProduct(Product $product, ?string $type = null, ?float $value = null, ?CarbonInterface $at = null): array
    {
        $at       = $at ?? Carbon::now();
        $sellerId = $product->seller_id !== null ? (int) $product->seller_id : null;

        $active    = $this->promo->getActivePromotionForProduct($product->id);
        $priced    = $this->promo->priceWith($product, null, null, $active);
        $unit      = round((float) $priced['final_price'], 3);
        $original  = (float) $priced['original_price'];
        $fundedM   = $active && $active->isPlatformFunded()
            ? max(0, Millimes::of($original) - Millimes::of($unit)) : 0;

        // Rate without ANY product override: what the reduction would apply to
        $normal = $this->commission->resolveForSeller($sellerId, $original);
        $plan   = SubscriptionPlan::forSlug($normal['plan']);
        $normalCalc = $this->commission->breakdownAtRate($normal['rate'], $normal['plan'], $unit, 1, 0.0, $original, $fundedM);

        $current = $this->commission->calculateForSeller($sellerId, $unit, 1, 0.0, $original, $fundedM, $product->id, $at);

        $out = [
            'product' => [
                'id' => (int) $product->id, 'name' => $product->name, 'seller_id' => $sellerId,
                'image' => $product->primary_image_url,
            ],
            'price' => ['unit' => $unit, 'original' => $original, 'platform_funded' => Millimes::toFloat($fundedM)],
            'floor' => $this->commission->floor(),
            'normal' => [
                'rate'          => $normal['rate'],
                'source'        => $normal['source'],          // override (seller-level) | plan | default
                'plan'          => $normal['plan'],
                'plan_name'     => $plan->name,
                'commission'    => $normalCalc['commission_amount'],
                'seller_amount' => $normalCalc['seller_amount'],
            ],
            'current' => [
                'rate'          => $current['commission_percentage'],
                'source'        => $current['commission_source'],
                'commission'    => $current['commission_amount'],
                'seller_amount' => $current['seller_amount'],
                'override_id'   => $current['commission_override_id'],
            ],
            'proposed' => null,
            'warnings' => [],
        ];

        if ($type !== null && $value !== null) {
            $rate   = CommissionService::applyOverride($type, $value, (float) $normal['rate']);
            $calc   = $this->commission->breakdownAtRate($rate, $normal['plan'], $unit, 1, 0.0, $original, $fundedM);
            $out['proposed'] = [
                'type'          => $type,
                'value'         => $value,
                'rate'          => $rate,
                'commission'    => $calc['commission_amount'],
                'seller_amount' => $calc['seller_amount'],
                'seller_gain'   => Millimes::toFloat($calc['seller_amount_m'] - $normalCalc['seller_amount_m']),
            ];
            if ($type === ProductCommissionOverride::TYPE_REDUCTION && $value > (float) $normal['rate']) {
                $out['warnings'][] = 'clamped_to_zero';
            }
            if ($rate < $this->commission->floor()) {
                $out['warnings'][] = 'below_floor';
            }
            if ($rate <= 0.0) {
                $out['warnings'][] = 'zero_commission';
            }
        }

        return $out;
    }

    /** The seller-facing description of a live special commission (no note, no admin ids). */
    private function offerBlock(?int $productId, CarbonInterface $at, array $calc): ?array
    {
        if ($calc['commission_override_id'] === null) {
            return null;
        }
        $o = $this->commission->activeProductOverride($productId, $at);
        if (!$o) {
            return null;
        }
        return [
            'type'        => $o->type,
            'value'       => $o->value,
            'rate'        => $calc['commission_percentage'],
            'normal_rate' => $calc['normal_rate'],
            'ends_at'     => optional($o->ends_at)->toISOString(),
        ];
    }
}
