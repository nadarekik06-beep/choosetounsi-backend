<?php

namespace App\Notifications\Orders;

/** A confirmed sub-order is still not marked ready for pickup. */
class SellerPickupReminderNotification extends SellerOrderNotification
{
    public function event(): string  { return 'pickup_reminder'; }
    protected function icon(): string   { return 'alert-triangle'; }
    protected function action(): string { return 'reminder'; }

    protected function withPickup(): bool
    {
        return true;
    }

    /** Only while it is still waiting to be prepared. */
    public function shouldSend($notifiable, string $channel): bool
    {
        return $this->sellerOrder->fresh()?->status === 'confirmed';
    }
}
