<?php

namespace App\Console\Commands\Notifications;

use App\Models\Coupon;
use App\Models\User;
use App\Notifications\Buyer\CouponExpiringNotification;
use App\Services\Notifications\BuyerNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly: a private coupon (Growth Radar audience) the buyer was told about and
 * hasn't used expires within config('notifications.coupon_expiry_hours') — one
 * reminder per buyer and coupon (promotions: preferences + daily cap apply).
 */
class SendCouponExpiryReminders extends Command
{
    protected $signature   = 'notifications:coupon-expiry';
    protected $description = 'Remind buyers that their unused private coupon expires soon';

    public function handle(BuyerNotifier $notifier): int
    {
        $hours = max(1, (int) config('notifications.coupon_expiry_hours'));
        $sent  = 0;

        $coupons = Coupon::where('is_active', true)
            ->whereBetween('expires_at', [now(), now()->addHours($hours)])
            ->whereExists(fn ($q) => $q->from('coupon_audiences as ca')->whereColumn('ca.coupon_id', 'coupons.id'))
            ->get();

        foreach ($coupons as $coupon) {
            if ($coupon->usage_limit && $coupon->usage_count >= $coupon->usage_limit) continue;

            $product = DB::table('coupon_products as cp')->join('products as p', 'p.id', '=', 'cp.product_id')
                ->where('cp.coupon_id', $coupon->id)->select('p.name', 'p.slug')->first();
            $shop = (string) DB::table('seller_applications')->where('user_id', $coupon->seller_id)
                ->where('status', 'approved')->orderByDesc('id')->value('business_name');

            $userIds = DB::table('coupon_audiences as ca')
                ->where('ca.coupon_id', $coupon->id)
                ->whereNotNull('ca.notified_at')
                ->whereNotExists(fn ($q) => $q->from('coupon_redemptions as r')
                    ->whereColumn('r.coupon_id', 'ca.coupon_id')->whereColumn('r.user_id', 'ca.user_id'))
                ->pluck('ca.user_id');

            foreach (User::whereIn('id', $userIds)->where('is_active', true)->get() as $user) {
                $notification = new CouponExpiringNotification($coupon, (string) ($product->name ?? ''), $product->slug ?? null, $shop ?: config('app.name'));
                if ($notifier->send($user, $notification)) $sent++;
            }
        }

        $this->info("Coupon expiry reminders sent: {$sent}");
        return self::SUCCESS;
    }
}
