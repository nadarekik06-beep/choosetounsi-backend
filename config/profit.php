<?php

/*
|--------------------------------------------------------------------------
| Centre de profit (Black Pepper) — money, goals and profitability
|--------------------------------------------------------------------------
| Every number comes from App\Services\Profit\SellerRevenueService, built on
| seller_orders (the frozen financial snapshot of each sub-order).
*/

return [

    // Month and day boundaries. The app itself runs in UTC.
    'timezone' => env('PROFIT_TIMEZONE', 'Africa/Tunis'),

    // Sub-order statuses that count as a sale (same as the sales forecast):
    // the seller confirmed it and it was not cancelled or fully returned.
    'sale_statuses' => ['confirmed', 'completed', 'out_for_delivery', 'delivered'],

    // Of those, the ones where the money is secured.
    'delivered_statuses' => ['delivered'],

    // ── Goal alerts (bell + optional e-mail) ──────────────────────────────
    'milestones' => [25, 50, 75, 100],

    // "Behind pace" alert: at most once every N days, never in the first days
    // of the month (too little data), only when the projection misses by ≥ X %.
    'pace_alert' => [
        'cooldown_days' => 3,
        'min_day'       => 5,
        'min_gap_pct'   => 10,
    ],

    // Defaults for sellers who never opened the alert settings.
    'alert_defaults' => [
        'enabled'           => true,
        'milestones'        => true,
        'pace'              => true,
        'weekly'            => false,
        'monthly_recap'     => true,
        'new_goal_reminder' => true,
        'channel_bell'      => true,
        'channel_email'     => false,
    ],
];
