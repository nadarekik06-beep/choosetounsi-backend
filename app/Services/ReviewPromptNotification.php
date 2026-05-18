<?php

namespace App\Notifications;

use App\Models\{SellerOrder, OrderItem};
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * In-app notification dispatched when a seller_order becomes 'delivered'.
 * Delivered via the standard Laravel database notification channel.
 * The storefront reads this via GET /api/notifications.
 */
class ReviewPromptNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected SellerOrder $sellerOrder,
        protected Collection|\Illuminate\Database\Eloquent\Collection $items,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $productNames = $this->items
            ->map(fn($i) => $i->product_name ?? $i->product?->name ?? 'Product')
            ->filter()
            ->take(2)
            ->join(', ');

        $firstItem = $this->items->first();

        return [
            'type'            => 'review_prompt',
            'title'           => 'How was your order?',
            'message'         => "Share your experience with {$productNames}",
            'order_id'        => $this->sellerOrder->order_id,
            'seller_order_id' => $this->sellerOrder->id,
            'product_image'   => $firstItem?->product?->primary_image_url,
            'items_count'     => $this->items->count(),
            'action_url'      => '/orders',
        ];
    }
}