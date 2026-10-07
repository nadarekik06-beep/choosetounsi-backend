<?php

namespace App\Notifications;

use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Notifications\Buyer\BuyerNotification;
use App\Notifications\Buyer\Concerns\DescribesOrder;

/**
 * "Rate your purchase": config('notifications.review_prompt_delay_days') after
 * delivery, for the items of one delivered sub-order the buyer hasn't reviewed
 * (notifications:review-reminders). The /orders popup keeps working from
 * review_prompts on its own.
 */
class ReviewPromptNotification extends BuyerNotification
{
    use DescribesOrder;

    /** @param int[] $orderItemIds */
    public function __construct(public SellerOrder $sellerOrder, public array $orderItemIds)
    {
        parent::__construct();
    }

    public function category(): string { return 'reviews'; }
    public function dedupeKey(): ?string { return "seller_order:{$this->sellerOrder->id}:review_prompt"; }

    protected function type(): string { return 'review_prompt'; }
    protected function icon(): string { return 'star'; }
    protected function action(): string { return 'review'; }
    protected function link(): ?string { return '/orders?order=' . $this->sellerOrder->order_id; }

    protected function title(): string { return __('buyer_notifications.review.prompt.title'); }

    protected function body(): string
    {
        return __('buyer_notifications.review.prompt.body', ['products' => $this->productNames()]);
    }

    protected function data(): array
    {
        return [
            'order_id'        => $this->sellerOrder->order_id,
            'seller_order_id' => $this->sellerOrder->id,
            'order_item_ids'  => array_values($this->orderItemIds),
        ];
    }

    protected function mailSubject(): string
    {
        return __('buyer_notifications.review.prompt.subject', ['products' => $this->productNames()]);
    }

    protected function mailButton(): string { return __('buyer_notifications.review.prompt.button'); }

    protected function mailLines(): array
    {
        $shop = $this->shopNames(collect([$this->sellerOrder->loadMissing('seller:id,name')]))[0] ?? config('app.name');
        return [__('buyer_notifications.review.prompt.line', ['shop' => $shop])];
    }

    private function productNames(): string
    {
        $names = OrderItem::whereIn('id', $this->orderItemIds)->pluck('product_name')->filter()->unique()->values();
        $shown = $names->take(2)->all();
        $more  = $names->count() - count($shown);

        return self::joinNames($shown) . ($more > 0 ? " (+{$more})" : '');
    }
}
