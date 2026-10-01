<?php

namespace App\Notifications\Orders;

/** The admin confirmed the order: prepare the package for the courier. */
class SellerOrderConfirmedNotification extends SellerOrderNotification
{
    public function event(): string  { return 'confirmed'; }
    protected function icon(): string   { return 'package-check'; }
    protected function action(): string { return 'approved'; }

    protected function withPickup(): bool
    {
        return true;
    }

    /** Cancelled while the job waited in the queue: don't ask to prepare it. */
    public function shouldSend($notifiable, string $channel): bool
    {
        return $this->sellerOrder->fresh()?->status !== 'cancelled';
    }
}
