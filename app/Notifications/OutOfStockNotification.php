<?php

namespace App\Notifications;

/**
 * Products/variants that just sold out, grouped (see StockAlertNotification,
 * StockAlertService). Always sent, even with low-stock alerts switched off.
 */
class OutOfStockNotification extends StockAlertNotification
{
    protected function kind(): string
    {
        return 'out_of_stock';
    }

    protected function action(): string
    {
        return 'out_of_stock';
    }
}
