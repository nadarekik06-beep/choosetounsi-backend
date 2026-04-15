<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Low Stock Threshold
    |--------------------------------------------------------------------------
    |
    | When a product's stock falls to or below this number, a "Low Stock"
    | notification is sent to the seller. Set per-product overrides in the
    | products table (low_stock_threshold column added by migration).
    |
    | Default: 5 units (configurable via .env)
    |
    */
    'low_stock_threshold' => (int) env('STOCK_LOW_THRESHOLD', 5),

    /*
    |--------------------------------------------------------------------------
    | Notification Cooldown (hours)
    |--------------------------------------------------------------------------
    |
    | Minimum hours between repeated notifications for the SAME product/variant
    | at the SAME level (low or out). Prevents spam when stock fluctuates
    | around the threshold boundary.
    |
    | Default: 24 hours
    |
    */
    'notification_cooldown_hours' => (int) env('STOCK_NOTIFICATION_COOLDOWN', 24),

];