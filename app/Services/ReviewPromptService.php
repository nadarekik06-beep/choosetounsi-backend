<?php

namespace App\Services;

use App\Models\{SellerOrder, ReviewPrompt, OrderItem};
use Illuminate\Support\Facades\Log;

/**
 * ReviewPromptService
 *
 * Called when a seller_order transitions to 'delivered'.
 * Creates ReviewPrompt records for each order item (for the popup system)
 * and optionally dispatches an email notification.
 *
 * HOW TO HOOK IT:
 *   In SellerOrderController::updateStatus(), after $sellerOrder->save(),
 *   add: ReviewPromptService::dispatch($sellerOrder);
 */
class ReviewPromptService
{
    public static function dispatch(SellerOrder $sellerOrder): void
    {
        try {
            if ($sellerOrder->status !== 'delivered') return;

            $order = $sellerOrder->order()->with('user')->first();
            if (!$order || !$order->user) return;

            // Get all order items for this seller_order
            $items = OrderItem::where('seller_order_id', $sellerOrder->id)
                ->with('product')
                ->get();

            foreach ($items as $item) {
                // Skip if already prompted
                $exists = ReviewPrompt::where('user_id', $order->user_id)
                    ->where('order_item_id', $item->id)
                    ->exists();

                if ($exists) continue;

                ReviewPrompt::create([
                    'user_id'      => $order->user_id,
                    'order_item_id'=> $item->id,
                    'product_id'   => $item->product_id,
                    'sent_at'      => now(),
                    'channel'      => 'popup',
                ]);
            }

            // Notify via Laravel notifications system (in-app)
            $user = $order->user;
            $user->notify(new \App\Notifications\ReviewPromptNotification($sellerOrder, $items));

        } catch (\Exception $e) {
            Log::error('[ReviewPromptService::dispatch] ' . $e->getMessage());
        }
    }
}