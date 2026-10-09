<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Low Stock Threshold (fallback)
    |--------------------------------------------------------------------------
    |
    | A product/variant is "low" at or below its threshold:
    |   products.low_stock_threshold → users.stock_alert_threshold (shop
    |   setting, default 2) → this value.
    |
    */
    'low_stock_threshold' => (int) env('STOCK_LOW_THRESHOLD', 2),

    /*
    |--------------------------------------------------------------------------
    | Alert grouping window (minutes)
    |--------------------------------------------------------------------------
    |
    | Crossings of the same seller within this window are sent as ONE
    | notification (App\Jobs\FlushStockAlerts runs this long after the first).
    | Crossings caused by the same order are always grouped.
    |
    */
    'alert_group_window_minutes' => (int) env('STOCK_ALERT_GROUP_WINDOW', 10),

];
