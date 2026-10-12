<?php

namespace App\Services\Intigo;

use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\Delivery\IntigoAdminNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the admins (bell) about a parcel that needs a human, and keeps the
 * reason on seller_orders.intigo_attention for the admin panel. The same
 * reason is notified once per parcel.
 */
class IntigoAlerts
{
    public function flag(SellerOrder|int $parcel, string $title, string $reason, string $kind = 'attention', bool $keepOnParcel = true): void
    {
        $so = $parcel instanceof SellerOrder ? $parcel : SellerOrder::find($parcel);
        if (!$so) return;

        try {
            if ($keepOnParcel) {
                DB::table('seller_orders')->where('id', $so->id)->update(['intigo_attention' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
            }

            // Once per parcel and reason (webhook retries, job retries, polling)
            $key = 'intigo:' . $so->id . ':' . md5($kind . $reason);
            $claimed = DB::table('notification_dispatches')->insertOrIgnore([
                'user_id' => 0, 'dedupe_key' => mb_substr($key, 0, 191), 'created_at' => now(),
            ]) === 1;
            if (!$claimed) return;

            $admins = User::where('role', 'admin')->where('is_active', true)->get();
            if ($admins->isEmpty()) return;

            $ref = DB::table('orders')->where('id', $so->order_id)->value('order_number');
            Notification::send($admins, new IntigoAdminNotification(
                $title,
                ($ref ? "{$ref}-S{$so->id}: " : "Parcel #{$so->id}: ") . $reason,
                (int) $so->order_id,
                $so->id,
                $kind,
            ));
        } catch (\Throwable $e) {
            Log::warning('[Intigo] admin alert failed: ' . $e->getMessage());
        }
    }
}
