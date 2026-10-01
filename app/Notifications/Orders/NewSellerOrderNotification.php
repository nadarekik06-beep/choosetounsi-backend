<?php

namespace App\Notifications\Orders;

use Illuminate\Support\Arr;

/**
 * A new sub-order for the seller, awaiting the admin's confirmation.
 * Informative only: what was ordered — no commission or earnings.
 */
class NewSellerOrderNotification extends SellerOrderNotification
{
    public function event(): string  { return 'placed'; }
    protected function icon(): string   { return 'package-plus'; }
    protected function action(): string { return 'created'; }

    protected function mailView(): string
    {
        return 'emails.seller-orders.placed';
    }

    protected function mailSummary(array $summary): array
    {
        return Arr::only($summary, ['reference', 'order_date', 'items', 'item_count', 'dashboard_url']);
    }
}
