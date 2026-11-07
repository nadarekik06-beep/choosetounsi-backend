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
        if (!$this->belongsTo($user, $order)) {
            return false;
        }

        return DB::transaction(function () use ($user, $order) {
            $updated = Order::whereKey($order->id)->whereNull('user_id')->update(['user_id' => $user->id]);
            if ($updated === 1) {
                $this->attachRelated($user, [$order->id]);
            }
            return $updated === 1;
        });
    }

    /**
     * Orders the buyer proves they hold (device tokens saved on this phone) and
     * that were placed with this account's phone or verified e-mail: added on
     * the buyer's request ("Add to my account"), never automatically.
     *
     * @param  string[] $tokens
     * @return int orders attached
     */
    public function claimTokens(User $user, array $tokens): int
    {
        $access = app(GuestOrderAccess::class);
        $count  = 0;
        foreach (array_slice(array_unique($tokens), 0, 50) as $token) {
            $order = $access->resolve(is_string($token) ? $token : null);
            if ($order && $order->user_id === null && $this->claimOne($user, $order)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Guest orders on this device the account could add (same phone / verified e-mail,
     * not taken yet) — what the "Add to my account" offer lists.
     *
     * @param  string[] $tokens
     * @return \Illuminate\Support\Collection<int, Order>
     */
    public function claimable(User $user, array $tokens)
    {
        $access = app(GuestOrderAccess::class);
        return collect(array_slice(array_unique($tokens), 0, 50))
            ->map(fn ($t) => $access->resolve(is_string($t) ? $t : null))
            ->filter(fn ($o) => $o && $o->user_id === null && $this->belongsTo($user, $o))
            ->unique('id')->values();
    }

    /** The order was placed with this account's phone or (verified) e-mail. */
    public function belongsTo(User $user, Order $order): bool
    {
        $email = strtolower(trim((string) $user->email));
        $phone = \App\Support\TunisianPhone::normalize((string) $user->phone);
        return ($email !== '' && $user->email_verified_at && $order->guest_email === $email)
            || ($phone && in_array($phone, array_filter([$order->guest_phone, $order->phone]), true));
    }

    /** Coupon uses and guest returns of these orders follow them to the account. */
    private function attachRelated(User $user, array $orderIds): void
    {
        CouponRedemption::whereIn('order_id', $orderIds)->whereNull('user_id')->update(['user_id' => $user->id]);
        \App\Models\Complaint::whereIn('order_id', $orderIds)->whereNull('user_id')->update(['user_id' => $user->id]);
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
                        if ($trackingToken && ($held = app(GuestOrderAccess::class)->resolve($trackingToken))) {
                            $q->orWhere('id', $held->id);
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
                $this->attachRelated($user, $ids->all());

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
