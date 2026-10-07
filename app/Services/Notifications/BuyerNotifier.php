<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\Buyer\BuyerNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one way to send a buyer notification:
 *
 *   - dedupe: a notification with a dedupeKey() is claimed once per user in
 *     notification_dispatches (unique key) — retries, double clicks and two code
 *     paths reporting the same status change send it once;
 *   - promotions: nothing when the user turned every channel off, and at most
 *     config('notifications.promotions_daily_cap') per user per day;
 *   - the claim is written in the caller's transaction and the queued notification
 *     is pushed after it commits: a rolled-back change sends nothing.
 *
 * Never throws: a notification must not break checkout or a status update.
 */
class BuyerNotifier
{
    public function send(?User $user, BuyerNotification $notification): bool
    {
        if (!$user) return false;

        try {
            if (!$this->claim($user, $notification)) {
                return false;
            }
        } catch (\Throwable $e) {
            Log::error('[BuyerNotifier] claim failed for ' . get_class($notification) . ": {$e->getMessage()}");
            return false;
        }

        // Queued with afterCommit() (BuyerNotification): the jobs are only pushed
        // once the caller's transaction commits, and dropped if it rolls back.
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            Log::error('[BuyerNotifier] ' . get_class($notification) . " for user {$user->id} could not be dispatched: {$e->getMessage()}");
        }

        return true;
    }

    /** Was this key already sent to the user? */
    public function sent(int $userId, string $key): bool
    {
        return DB::table('notification_dispatches')->where('user_id', $userId)->where('dedupe_key', $key)->exists();
    }

    private function claim(User $user, BuyerNotification $notification): bool
    {
        $key = $notification->dedupeKey();

        if ($notification->category() === 'promotions') {
            if ($notification->via($user) === []) return false;
            if ($key && $this->sent($user->id, $key)) return false;
            if (!$this->claimPromotionSlot($user->id)) return false;
        }

        return $key === null || $this->insert($user->id, $key);
    }

    private function claimPromotionSlot(int $userId): bool
    {
        $day = now()->timezone(config('notifications.timezone'))->toDateString();
        $cap = max(0, (int) config('notifications.promotions_daily_cap'));

        for ($slot = 1; $slot <= $cap; $slot++) {
            if ($this->insert($userId, "promo-day:{$day}:{$slot}")) return true;
        }
        return false;
    }

    private function insert(int $userId, string $key): bool
    {
        return DB::table('notification_dispatches')->insertOrIgnore([
            'user_id'    => $userId,
            'dedupe_key' => mb_substr($key, 0, 191),
            'created_at' => now(),
        ]) === 1;
    }
}
