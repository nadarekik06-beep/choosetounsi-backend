<?php

namespace App\Services\GrowthRadar;

use App\Models\Coupon;
use App\Models\User;
use App\Mail\Growth\TargetedCouponMail;
use App\Notifications\Growth\TargetedCouponNotification;
use App\Notifications\Support\NotificationPreferences;
use App\Services\Ads\MarketingConsent;
use App\Services\Notifications\BuyerNotifier;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Warm audience" of a product: buyers who favourited it or added it to their
 * cart recently and did not buy it. The seller only ever gets a count; the ids
 * stay on the server, in coupon_audiences.
 *
 * Guards: at least config('growth.warm.min_audience') buyers (so nobody can be
 * singled out), and a buyer gets at most one targeted coupon per
 * config('growth.warm.buyer_cap_days') days across all sellers.
 */
class Audience
{
    /** @return array{favorites: int, carts: int, total: int, eligible: int[]} */
    public function forProduct(int $sellerId, int $productId): array
    {
        $favDays  = (int) config('growth.warm.favorite_days');
        $cartDays = (int) config('growth.warm.cart_days');

        $favorites = DB::table('favorites')->where('product_id', $productId)
            ->where('created_at', '>=', now()->subDays($favDays))->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $carts = DB::table('user_interactions')->where('product_id', $productId)->where('event_type', 'cart_add')
            ->whereNotNull('user_id')->where('created_at', '>=', now()->subDays($cartDays))
            ->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $carts = array_values(array_unique(array_merge($carts,
            DB::table('carts')->where('product_id', $productId)->pluck('user_id')->map(fn ($id) => (int) $id)->all())));

        $bought = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $productId)->where('o.created_at', '>=', now()->subDays(max($favDays, $cartDays)))
            ->whereNotIn('o.status', ['cancelled'])->pluck('o.user_id')->map(fn ($id) => (int) $id)->flip()->all();

        $favorites = array_values(array_filter($favorites, fn ($id) => !isset($bought[$id]) && $id !== $sellerId));
        $carts     = array_values(array_filter($carts, fn ($id) => !isset($bought[$id]) && $id !== $sellerId));
        $all = array_values(array_unique(array_merge($favorites, $carts)));

        $all = $all ? DB::table('users')->whereIn('id', $all)->where('is_active', true)
            ->where('role', 'client')->pluck('id')->map(fn ($id) => (int) $id)->all() : [];

        $capped = $all ? DB::table('coupon_audiences')->whereIn('user_id', $all)
            ->where('created_at', '>=', now()->subDays((int) config('growth.warm.buyer_cap_days')))
            ->pluck('user_id')->map(fn ($id) => (int) $id)->flip()->all() : [];

        return [
            'favorites' => count(array_intersect($favorites, $all)),
            'carts'     => count(array_intersect($carts, $all)),
            'total'     => count($all),
            'eligible'  => array_values(array_filter($all, fn ($id) => !isset($capped[$id]))),
        ];
    }

    public static function minimum(): int
    {
        return (int) config('growth.warm.min_audience');
    }

    /**
     * Restrict a new coupon to the product's warm audience. Call inside the coupon's
     * transaction. Returns the audience size, or null when it is below the minimum
     * (the caller must then refuse the coupon).
     */
    public function attach(Coupon $coupon, int $sellerId, int $productId): ?int
    {
        $ids = $this->forProduct($sellerId, $productId)['eligible'];
        if (count($ids) < self::minimum()) return null;

        $now = now();
        DB::table('coupon_audiences')->insert(array_map(fn ($id) => [
            'coupon_id' => $coupon->id, 'user_id' => $id, 'created_at' => $now,
        ], $ids));
        return count($ids);
    }

    /**
     * Tell each buyer about their code: bell unless they turned promotions off (and at
     * most one promotion a day — BuyerNotifier), e-mail only with marketing consent.
     */
    public function notify(Coupon $coupon): int
    {
        $sent = 0;
        $ids = DB::table('coupon_audiences')->where('coupon_id', $coupon->id)->whereNull('notified_at')->pluck('user_id');
        $product = DB::table('coupon_products as cp')->join('products as p', 'p.id', '=', 'cp.product_id')
            ->where('cp.coupon_id', $coupon->id)->select('p.name', 'p.slug')->first();
        $shop = (string) DB::table('seller_applications')->where('user_id', $coupon->seller_id)->value('business_name');
        $front = rtrim((string) config('app.frontend_url'), '/');
        $consent = app(MarketingConsent::class);
        $notifier = app(BuyerNotifier::class);

        foreach (User::whereIn('id', $ids)->get() as $user) {
            try {
                $bell = $notifier->send($user, TargetedCouponNotification::for($coupon, $product, $shop));
                // Same opt-in rule as the promotions category; bell off doesn't stop a wanted e-mail.
                $email = NotificationPreferences::allows($user, 'promotions', 'email')
                    && ($bell || !NotificationPreferences::allows($user, 'promotions', 'in_app'));
                if ($email) {
                    // Texts are rendered when the mail is built, in the buyer's locale (Mail::to($user))
                    Mail::to($user)->queue(new TargetedCouponMail($user, [
                        'code'           => $coupon->code,
                        'discount_type'  => $coupon->discount_type,
                        'discount_value' => (float) $coupon->discount_value,
                        'product'        => (string) ($product->name ?? ''),
                        'shop'           => $shop,
                        'url'            => $front . ($product ? "/products/{$product->slug}" : '/') . '?utm_source=email&utm_medium=coupon',
                        'expires_at'     => $coupon->expires_at?->toDateString(),
                    ], $consent->unsubscribeUrl($user)));
                }
                DB::table('coupon_audiences')->where('coupon_id', $coupon->id)->where('user_id', $user->id)
                    ->update(['notified_at' => now(), 'emailed_at' => $email ? now() : null]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('[GrowthRadar] Coupon notification failed: ' . $e->getMessage(), ['coupon' => $coupon->id]);
            }
        }
        return $sent;
    }

    /** Is this buyer allowed to use the coupon? (true for coupons without an audience) */
    public static function allows(int $couponId, int $userId): bool
    {
        $restricted = DB::table('coupon_audiences')->where('coupon_id', $couponId)->exists();
        return !$restricted || DB::table('coupon_audiences')->where('coupon_id', $couponId)->where('user_id', $userId)->exists();
    }
}
