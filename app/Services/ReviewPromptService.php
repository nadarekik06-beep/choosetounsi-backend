<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\ReviewPrompt;
use App\Models\SellerOrder;
use App\Notifications\ReviewPromptNotification;
use App\Services\Notifications\BuyerNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Rate your purchase":
 *
 *   dispatch()   a sub-order was delivered: one review_prompts row per item
 *                (idempotent). The /orders popup reads them right away
 *                (GET /api/client/reviews/prompts). Called by BuyerOrderNotifier
 *                for every delivered path (seller, courier, admin).
 *   remindDue()  config('notifications.review_prompt_delay_days') days later, one
 *                bell + e-mail per delivered sub-order for the items still not
 *                reviewed (notifications:review-reminders, hourly). Each item is
 *                reminded once (review_prompts.notified_at).
 */
class ReviewPromptService
{
    public static function dispatch(SellerOrder $sellerOrder): void
    {
        try {
            if ($sellerOrder->status !== 'delivered') return;

            $userId = (int) $sellerOrder->order()->value('user_id');
            if (!$userId) return;

            $items = OrderItem::where('seller_order_id', $sellerOrder->id)
                ->whereNotNull('product_id')
                ->get(['id', 'product_id']);

            foreach ($items as $item) {
                ReviewPrompt::firstOrCreate(
                    ['user_id' => $userId, 'order_item_id' => $item->id],
                    ['product_id' => $item->product_id, 'sent_at' => now(), 'channel' => 'popup'],
                );
            }
        } catch (\Throwable $e) {
            Log::error('[ReviewPromptService::dispatch] ' . $e->getMessage(), ['seller_order_id' => $sellerOrder->id]);
        }
    }

    /** @return int notifications sent */
    public function remindDue(): int
    {
        $delay  = max(0, (int) config('notifications.review_prompt_delay_days'));
        $window = max(1, (int) config('notifications.review_prompt_window_days'));
        $sent   = 0;

        // Prompts created delay..delay+window days ago, never reminded, not answered.
        $rows = DB::table('review_prompts as rp')
            ->join('order_items as oi', 'oi.id', '=', 'rp.order_item_id')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->whereNull('rp.notified_at')
            ->whereNull('rp.reviewed_at')
            ->whereNull('rp.dismissed_at')
            ->where('rp.sent_at', '<=', now()->subDays($delay))
            ->where('rp.sent_at', '>', now()->subDays($delay + $window))
            ->where('so.status', 'delivered')
            ->whereNotExists(fn ($q) => $q->from('reviews as r')
                ->whereColumn('r.user_id', 'rp.user_id')
                ->whereColumn('r.order_item_id', 'rp.order_item_id'))
            ->select('rp.id', 'rp.user_id', 'rp.order_item_id', 'so.id as seller_order_id')
            ->orderBy('rp.id')
            ->get();

        $notifier = app(BuyerNotifier::class);

        foreach ($rows->groupBy(fn ($r) => $r->user_id . ':' . $r->seller_order_id) as $group) {
            $first       = $group->first();
            $sellerOrder = SellerOrder::with('order.user')->find($first->seller_order_id);
            $user        = $sellerOrder?->order?->user;

            // Marked first: whatever happens, an item is never reminded twice.
            DB::table('review_prompts')->whereIn('id', $group->pluck('id'))->update(['notified_at' => now()]);

            if ($user && (int) $user->id === (int) $first->user_id
                && $notifier->send($user, new ReviewPromptNotification($sellerOrder, $group->pluck('order_item_id')->all()))) {
                $sent++;
            }
        }

        return $sent;
    }
}
