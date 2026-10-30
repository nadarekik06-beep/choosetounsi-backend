<?php

namespace App\Services\Orders;

use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A NEW account takes over its guest orders — only what it can prove is its own:
 *
 *   - the order whose tracking token the browser holds (sent with the signup), and
 *   - guest orders placed with the same e-mail, once that e-mail is verified
 *     (code by e-mail, or Google).
 *
 * Never by phone alone (phones are not verified), and never automatically for
 * an account that already existed: that buyer logs in, opens the tracking
 * link and taps "Add to my account" (claimOne: e-mail or phone must match).
 * The order stays is_guest = true (how it was placed); user_id is set so it
 * shows under /orders and counts for the coupon per-customer limit.
 */
class GuestOrderClaimer
{
    /**
     * An EXISTING account asks to add one guest order (it holds the tracking
     * link and is signed in): only when the order's e-mail or phone is this
     * account's. Returns false when it doesn't match or is already taken.
     */
    public function claimOne(User $user, Order $order): bool
    {
        if (!$order->is_guest || $order->user_id !== null) {
            return false;
        }
        $email = strtolower(trim((string) $user->email));
        $phone = \App\Support\TunisianPhone::normalize((string) $user->phone);
        $matches = ($email !== '' && $user->email_verified_at && $order->guest_email === $email)
            || ($phone && in_array($phone, array_filter([$order->guest_phone, $order->phone]), true));
        if (!$matches) {
            return false;
        }

        return DB::transaction(function () use ($user, $order) {
            $updated = Order::whereKey($order->id)->whereNull('user_id')->update(['user_id' => $user->id]);
            CouponRedemption::where('order_id', $order->id)->whereNull('user_id')->update(['user_id' => $user->id]);
            return $updated === 1;
        });
    }

    /** @return int orders attached */
    public function claim(User $user, ?string $trackingToken = null): int
    {
        try {
            $email = strtolower(trim((string) $user->email));
            if (!$user->email_verified_at && !$trackingToken) {
                return 0;
            }

            return DB::transaction(function () use ($user, $email, $trackingToken) {
                $ids = Order::where('is_guest', true)
                    ->whereNull('user_id')
                    ->where(function ($q) use ($user, $email, $trackingToken) {
                        if ($trackingToken) {
                            $q->orWhere('tracking_token', $trackingToken);
                        }
                        if ($user->email_verified_at && $email !== '') {
                            $q->orWhere('guest_email', $email);
                        }
                    })
                    ->lockForUpdate()
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    return 0;
                }

                Order::whereIn('id', $ids)->update(['user_id' => $user->id]);
                CouponRedemption::whereIn('order_id', $ids)->whereNull('user_id')->update(['user_id' => $user->id]);

                Log::info('[GuestOrderClaimer] user #' . $user->id . ' took over guest orders ' . $ids->implode(','));
                return $ids->count();
            });
        } catch (\Throwable $e) {
            // Never break signup because of this.
            Log::error('[GuestOrderClaimer] ' . $e->getMessage());
            return 0;
        }
    }
}
