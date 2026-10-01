<?php

namespace App\Notifications\Orders;

use Illuminate\Support\Arr;

/**
 * The order was cancelled after the seller heard about it: do not ship.
 * Minimal: reference + cancelled products, no amounts and no reason.
 * (Sub-orders cancel as a whole — items have no status of their own — so
 * every item of the sub-order is listed.)
 */
class SellerOrderCancelledNotification extends SellerOrderNotification
{
    public function event(): string  { return 'cancelled'; }
    protected function icon(): string   { return 'package-x'; }
    protected function action(): string { return 'rejected'; }

    protected function mailView(): string
    {
        return 'emails.seller-orders.cancelled';
    }

    protected function mailSummary(array $summary): array
    {
        return [
            'reference'     => $summary['reference'],
            'items'         => array_map(fn($item) => Arr::only($item, ['name', 'variant', 'qty']), $summary['items']),
            'dashboard_url' => $summary['dashboard_url'],
        ];
    }
}
