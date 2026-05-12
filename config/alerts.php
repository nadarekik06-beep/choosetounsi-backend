<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Product Alert Thresholds
    |--------------------------------------------------------------------------
    | Configurable via .env. Lower values = more sensitive alerts.
    | Set ALERT_DEBUG_MODE=true in .env to trigger alerts on every product.
    */

    'min_listing_age_days' => (int) env('ALERT_MIN_LISTING_AGE_DAYS', 30),
    'window_days'          => (int) env('ALERT_WINDOW_DAYS',          30),
    'low_sales_threshold'  => (int) env('ALERT_LOW_SALES_THRESHOLD',   3),
    'high_stock_threshold' => (int) env('ALERT_HIGH_STOCK_THRESHOLD', 15),
    'stock_sales_ratio'    => (float) env('ALERT_STOCK_SALES_RATIO', 10.0),
    'low_views_threshold'  => (int) env('ALERT_LOW_VIEWS_THRESHOLD',  50),
    'debug_mode'           => (bool) env('ALERT_DEBUG_MODE',         false),
];