<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Services\GrowthRadar\Audience;
use App\Support\Millimes;
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
    /**
     * $userId null = guest: targeted coupons are refused, and the "per customer"
     * limit counts by $guestPhone — every earlier order delivered to that phone
     * (guest or account), so checking out as a guest never resets the limit.
     */
    public function validateForSeller(string $code, int $sellerId, ?int $userId, array $items, ?string $guestPhone = null): array
    {
        $invalid = fn (string $message) => [
            'valid' => false, 'message' => $message, 'coupon' => null,
            'discount_amount' => 0.0, 'eligible_product_ids' => [],
        ];

        $coupon = Coupon::where('code', $code)->where('seller_id', $sellerId)->first();

        if (!$coupon) {
            return $invalid(__('messages.coupon.invalid_for_seller'));
        }
        if (!$coupon->is_active) {
            return $invalid(__('messages.coupon.inactive'));
        }
        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            return $invalid(__('messages.coupon.expired'));
        }
        // Targeted coupon (Growth Radar): only its audience may use it
        if (!Audience::allows($coupon->id, $userId)) {
            return $invalid(__('messages.coupon.not_for_you'));
        }
        if (!$coupon->hasUsesRemaining()) {
            return $invalid(__('messages.coupon.limit_reached'));
        }
        if ($coupon->usage_limit_per_customer !== null && ($userId !== null || $guestPhone)) {
            $customerUses = $userId !== null
                ? CouponRedemption::where('coupon_id', $coupon->id)
                    ->where('user_id', $userId)
                    ->count()
                : $this->usesByPhone($coupon->id, $guestPhone);
            if ($customerUses >= $coupon->usage_limit_per_customer) {
                return $invalid(__('messages.coupon.customer_limit'));
            }
        }

        $eligibleProductIds = array_map('intval', $coupon->products()->pluck('products.id')->toArray());

        $eligibleItems = collect($items)->filter(
            fn ($item) => in_array((int) $item['product_id'], $eligibleProductIds, true)
        );

        if ($eligibleItems->isEmpty()) {
            return $invalid(__('messages.coupon.no_eligible_items'));
        }

        // Never stacked (AUDIT M-5): items already on promotion / flash sale are
        // skipped; the coupon is refused only when none of its items remains.
        // `promoted` = the promotion actually charged on the line (checkout passes it).
        $promoService = app(PromotionService::class);
        [$promoted, $applicable] = $eligibleItems->partition(
            fn ($item) => $item['promoted'] ?? $promoService->getActivePromotionForProduct((int) $item['product_id']) !== null
        );
        if ($applicable->isEmpty()) {
            return $invalid(__('messages.coupon.all_items_promoted'));
        }

        // Integer millimes; a percentage discount is rounded half-up once.
        $eligibleSubtotal = $applicable->sum(fn ($item) => Millimes::of($item['line_total']));

        if ($coupon->min_order_amount !== null && $eligibleSubtotal < Millimes::of($coupon->min_order_amount)) {
            return $invalid(__('messages.coupon.min_order', ['amount' => number_format((float) $coupon->min_order_amount, 3)]));
        }

        $discount = $coupon->discount_type === 'percentage'
            ? min(Millimes::percent($eligibleSubtotal, $coupon->discount_value), $eligibleSubtotal)
            : min(Millimes::of($coupon->discount_value), $eligibleSubtotal);

        return [
            'valid'                 => true,
            'message'               => $promoted->isEmpty() ? __('messages.coupon.applied') : __('messages.coupon.applied_partial'),
            'coupon'                => $coupon,
            'discount_amount'       => Millimes::toFloat($discount),
            'discount_amount_m'     => $discount,
            'eligible_product_ids'  => $applicable->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->toArray(),
            'skipped_product_ids'   => $promoted->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->toArray(),
        ];
    }

    /**
     * Split a seller coupon's discount across the eligible lines, proportional
     * to each line's total. Rounded to the millime; the rounding remainder goes
     * to the last line so the shares always sum exactly to $discount.
     *
     * @param array<int|string, float> $lineTotals key => line_total of ELIGIBLE lines only
     * @return array<int|string, float>             key => discount share
     */
    public function allocateDiscount(array $lineTotals, float $discount): array
    {
        $shares = $this->allocateDiscountM(
            array_map(fn ($t) => Millimes::of($t), $lineTotals),
            Millimes::of($discount)
        );
        return array_map(fn (int $m) => Millimes::toFloat($m), $shares);
    }

    /**
     * Same split in integer millimes: half-up share per line, the remainder on
     * the last line, never more than a line's own total.
     *
     * @param array<int|string, int> $lineTotals
     * @return array<int|string, int>
     */
    public function allocateDiscountM(array $lineTotals, int $discount): array
    {
        $base = array_sum($lineTotals);
        if ($discount <= 0 || $base <= 0) {
            return array_map(fn () => 0, $lineTotals);
        }

        $shares    = [];
        $allocated = 0;
        $lastKey   = array_key_last($lineTotals);

        foreach ($lineTotals as $key => $lineTotal) {
            $share = $key === $lastKey
                ? $discount - $allocated
                : Millimes::share($discount, $lineTotal, $base);
            $share        = min($share, $lineTotal);
            $shares[$key] = $share;
            $allocated   += $share;
        }

        return $shares;
    }

    /** Coupon uses on orders delivered to this phone (guest or account orders). $lock: locking read. */
    public function usesByPhone(int $couponId, string $phone, bool $lock = false): int
    {
        $q = CouponRedemption::where('coupon_id', $couponId)
            ->where(fn ($q) => $q->where('guest_phone', $phone)
                ->orWhereIn('order_id', Order::where('phone', $phone)->select('id')));
        if ($lock) {
            $q->sharedLock();
        }
        return $q->count();
    }

    /**
     * Record a redemption and increment usage_count, inside the checkout
     * transaction. Under a row lock on the coupon, BOTH limits are checked
     * again with locking reads (latest committed rows): two orders placed at
     * the same moment can't both take the last use, nor the customer's last
     * use (AUDIT M-2). $userId null = guest order: counted by $guestPhone.
     *
     * @throws \App\Exceptions\CouponExhausted
     */
    public function redeem(Coupon $coupon, SellerOrder $sellerOrder, Order $order, ?int $userId, float $discountAmount, ?string $guestPhone = null): void
    {
        DB::transaction(function () use ($coupon, $sellerOrder, $order, $userId, $discountAmount, $guestPhone) {
            $locked = Coupon::where('id', $coupon->id)->lockForUpdate()->first();

            if (!$locked || !$locked->is_active || !$locked->hasUsesRemaining()) {
                throw new \App\Exceptions\CouponExhausted($coupon->code);
            }
            if ($locked->usage_limit_per_customer !== null && ($userId !== null || $guestPhone)) {
                $uses = $userId !== null
                    ? CouponRedemption::where('coupon_id', $locked->id)->where('user_id', $userId)->sharedLock()->count()
                    : $this->usesByPhone($locked->id, $guestPhone, true);
                if ($uses >= $locked->usage_limit_per_customer) {
                    throw new \App\Exceptions\CouponExhausted($coupon->code);
                }
            }

            CouponRedemption::create([
                'coupon_id'       => $locked->id,
                'order_id'        => $order->id,
                'seller_order_id' => $sellerOrder->id,
                'user_id'         => $userId,
                'guest_phone'     => $userId === null ? $guestPhone : null,
                'discount_amount' => $discountAmount,
            ]);

            $locked->increment('usage_count');
        });
    }

    /** Undo redeem() for a cancelled order: the customer gets the coupon use back. */
    public function releaseForOrder(Order $order): void
    {
        $this->releaseWhere(fn ($q) => $q->where('order_id', $order->id));
    }

    /**
     * Cancelled parcels (any path, AUDIT H-2): their coupon use is given back.
     * Idempotent: a redemption is deleted once, under lock; a second call finds none.
     */
    public function releaseForSellerOrders(array $sellerOrderIds): int
    {
        return $sellerOrderIds ? $this->releaseWhere(fn ($q) => $q->whereIn('seller_order_id', $sellerOrderIds)) : 0;
    }

    /** Safety net (promotions:sync): every cancelled parcel still holding a coupon use. */
    public function releaseCancelled(): int
    {
        return $this->releaseWhere(fn ($q) => $q->whereIn('seller_order_id',
            DB::table('seller_orders')->where('status', 'cancelled')->select('id')));
    }

    /**
     * A cancelled parcel re-opened: it keeps the coupon price it was sold at,
     * so the use is recorded again (even past the limit: the discount was
     * already granted on this order). Idempotent.
     */
    public function reclaimForSellerOrders(array $sellerOrderIds): int
    {
        $n = 0;
        foreach (SellerOrder::whereIn('id', $sellerOrderIds)->whereNotNull('coupon_id')->get() as $so) {
            if ($so->status === 'cancelled' || CouponRedemption::where('seller_order_id', $so->id)->exists()) {
                continue;
            }
            DB::transaction(function () use ($so, &$n) {
                $coupon = Coupon::withTrashed()->where('id', $so->coupon_id)->lockForUpdate()->first();
                if (!$coupon) {
                    return;
                }
                $order = Order::find($so->order_id);
                $guest = $order && $order->user_id === null;
                CouponRedemption::create([
                    'coupon_id'       => $coupon->id,
                    'order_id'        => $so->order_id,
                    'seller_order_id' => $so->id,
                    'user_id'         => $order ? $order->user_id : null,
                    'guest_phone'     => $guest ? ($order->guest_phone ?? $order->phone) : null,
                    'discount_amount' => $so->discount_amount,
                ]);
                $coupon->increment('usage_count');
                $n++;
            });
        }
        return $n;
    }

    private function releaseWhere(\Closure $scope): int
    {
        return DB::transaction(function () use ($scope) {
            $released = 0;
            foreach (CouponRedemption::where($scope)->lockForUpdate()->get() as $redemption) {
                Coupon::withTrashed()->where('id', $redemption->coupon_id)->where('usage_count', '>', 0)->decrement('usage_count');
                $redemption->delete();
                $released++;
            }
            return $released;
        });
    }
}
