<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\SellerOrder;
use Illuminate\Support\Facades\DB;

class CouponService
{
    /**
     * Validate a coupon code against ONE seller's items in the customer's cart.
     * Never trusts a discount amount from the client — always recomputed here.
     *
     * @param array $items [{product_id: int, quantity: int, line_total: float}, ...]
     *                      line_total is that item's post-promotion effective total
     *                      (same figure SellerOrder.subtotal is built from).
     *
     * @return array{
     *   valid: bool, message: string, coupon: ?Coupon,
     *   discount_amount: float, eligible_product_ids: int[]
     * }
     */
    public function validateForSeller(string $code, int $sellerId, int $userId, array $items): array
    {
        $invalid = fn (string $message) => [
            'valid' => false, 'message' => $message, 'coupon' => null,
            'discount_amount' => 0.0, 'eligible_product_ids' => [],
        ];

        $coupon = Coupon::where('code', $code)->where('seller_id', $sellerId)->first();

        if (!$coupon) {
            return $invalid('Invalid coupon code for this seller.');
        }
        if (!$coupon->is_active) {
            return $invalid('This coupon is no longer active.');
        }
        if (!$coupon->hasUsesRemaining()) {
            return $invalid('This coupon has reached its usage limit.');
        }
        if ($coupon->usage_limit_per_customer !== null) {
            $customerUses = CouponRedemption::where('coupon_id', $coupon->id)
                ->where('user_id', $userId)
                ->count();
            if ($customerUses >= $coupon->usage_limit_per_customer) {
                return $invalid('You have already used this coupon the maximum number of times.');
            }
        }

        $eligibleProductIds = $coupon->products()->pluck('products.id')->toArray();

        $eligibleItems = collect($items)->filter(
            fn ($item) => in_array($item['product_id'], $eligibleProductIds, true)
        );

        if ($eligibleItems->isEmpty()) {
            return $invalid('None of your items from this seller are eligible for this coupon.');
        }

        $promoService = app(PromotionService::class);
        foreach ($eligibleItems as $item) {
            if ($promoService->getActivePromotionForProduct($item['product_id'])) {
                return $invalid('This coupon cannot be combined with an active promotion on one of its eligible items.');
            }
        }

        $eligibleSubtotal = (float) $eligibleItems->sum('line_total');

        if ($coupon->min_order_amount !== null && $eligibleSubtotal < (float) $coupon->min_order_amount) {
            return $invalid(sprintf(
                'A minimum order of %s DT (eligible items) is required for this coupon.',
                number_format((float) $coupon->min_order_amount, 3)
            ));
        }

        $discount = $coupon->discount_type === 'percentage'
            ? $eligibleSubtotal * ((float) $coupon->discount_value / 100)
            : min((float) $coupon->discount_value, $eligibleSubtotal);

        return [
            'valid'                 => true,
            'message'               => 'Coupon applied.',
            'coupon'                => $coupon,
            'discount_amount'       => round($discount, 3),
            'eligible_product_ids'  => $eligibleItems->pluck('product_id')->values()->toArray(),
        ];
    }

    /**
     * Record a redemption and increment usage_count atomically.
     * Re-checks the total usage limit under a row lock — the per-customer
     * limit is re-validated by the caller re-running validateForSeller()
     * immediately beforehand, not locked here.
     *
     * @throws \RuntimeException if the coupon's usage limit was hit concurrently.
     */
    public function redeem(Coupon $coupon, SellerOrder $sellerOrder, Order $order, int $userId, float $discountAmount): void
    {
        DB::transaction(function () use ($coupon, $sellerOrder, $order, $userId, $discountAmount) {
            $locked = Coupon::where('id', $coupon->id)->lockForUpdate()->first();

            if (!$locked || !$locked->hasUsesRemaining()) {
                throw new \RuntimeException('Coupon usage limit was reached.');
            }

            CouponRedemption::create([
                'coupon_id'       => $locked->id,
                'order_id'        => $order->id,
                'seller_order_id' => $sellerOrder->id,
                'user_id'         => $userId,
                'discount_amount' => $discountAmount,
            ]);

            $locked->increment('usage_count');
        });
    }
}
