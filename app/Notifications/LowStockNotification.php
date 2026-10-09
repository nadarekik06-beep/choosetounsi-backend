<?php

namespace App\Notifications;

/**
 * Products/variants that just dropped to or below their low-stock threshold
 * after a sale, grouped (see StockAlertNotification, StockAlertService).
 */
class LowStockNotification extends StockAlertNotification
{
    protected function kind(): string
    {
        return 'low_stock';
    }

    protected function action(): string
    {
        return 'low_stock';
    }
}
