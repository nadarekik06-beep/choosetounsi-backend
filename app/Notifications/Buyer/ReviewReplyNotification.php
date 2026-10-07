<?php

namespace App\Notifications\Buyer;

use App\Models\Review;
use App\Models\ReviewReply;
use App\Notifications\Buyer\Concerns\DescribesOrder;
use Illuminate\Support\Str;

/** The shop answered the buyer's review (sent once per reply). */
class ReviewReplyNotification extends BuyerNotification
{
    use DescribesOrder;

    public function __construct(public ReviewReply $reply)
    {
        parent::__construct();
    }

    public function category(): string { return 'reviews'; }
    public function dedupeKey(): ?string { return "review_reply:{$this->reply->id}"; }

    protected function type(): string { return 'review_reply'; }
    protected function icon(): string { return 'message-circle'; }
    protected function action(): string { return 'review'; }

    protected function link(): ?string
    {
        $slug = $this->review()?->product?->slug;
        return $slug ? "/products/{$slug}#reviews" : '/profile?tab=reviews';
    }

    protected function title(): string { return __('buyer_notifications.review.reply.title', $this->params()); }
    protected function body(): string { return __('buyer_notifications.review.reply.body', $this->params()); }
    protected function mailSubject(): string { return __('buyer_notifications.review.reply.subject', $this->params()); }
    protected function mailButton(): string { return __('buyer_notifications.review.reply.button'); }

    protected function data(): array
    {
        return [
            'review_id'  => $this->reply->review_id,
            'reply_id'   => $this->reply->id,
            'product_id' => $this->review()?->product_id,
        ];
    }

    private function review(): ?Review
    {
        return $this->reply->loadMissing('review.product:id,name,slug')->review;
    }

    private function params(): array
    {
        $review = $this->review();
        $shop   = $this->shopNames(collect([(object) ['seller_id' => $this->reply->seller_id, 'seller' => null]]))[0]
            ?? config('app.name');

        return [
            'shop'    => $shop,
            'product' => (string) ($review?->product?->name ?? ''),
            'excerpt' => Str::limit((string) $this->reply->body, 120),
        ];
    }
}
