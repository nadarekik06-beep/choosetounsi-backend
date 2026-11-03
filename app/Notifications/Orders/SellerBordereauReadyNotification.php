<?php

namespace App\Notifications\Orders;

/**
 * The Intigo parcel exists: the seller can print the bordereau from the order
 * page (the e-mail links to the dashboard, never to the PDF). Bell + e-mail,
 * once per seller_order. Sent through SellerOrderNotifier::bordereauReady().
 */
class SellerBordereauReadyNotification extends SellerOrderNotification
{
    public function event(): string  { return 'bordereau_ready'; }
    protected function icon(): string   { return 'printer'; }
    protected function action(): string { return 'order'; }

    protected function mailView(): string
    {
        return 'emails.seller-orders.bordereau-ready';
    }

    protected function mailSummary(array $summary): array
    {
        return [
            'reference'     => $summary['reference'],
            'dashboard_url' => $summary['dashboard_url'],
        ];
    }

    /** Not if the parcel was cancelled before the e-mail went out. */
    public function shouldSend($notifiable, string $channel): bool
    {
        $so = $this->sellerOrder->fresh();
        return $so && \App\Services\Intigo\BordereauService::availableToSeller($so) && $so->status !== 'cancelled';
    }
}
