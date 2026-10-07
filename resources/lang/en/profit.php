<?php

// Profit Center (Black Pepper): goal API messages, alerts, CSV export.
return [
    'goal' => [
        'saved'           => 'Goal saved.',
        'deleted'         => 'Goal deleted.',
        'month_invalid'   => 'You can set a goal for this month or next month only.',
        'net_above_sales' => 'The net earnings target cannot be higher than the sales goal.',
    ],
    'alerts' => [
        'saved' => 'Alert preferences saved.',
    ],
    'notify' => [
        'cta'    => 'Open my Profit Center',
        'footer' => 'You are receiving this e-mail because goal alerts are on in your Profit Center. Turn them off anytime from the bell on your goal.',

        'milestone_title'      => ':pct% of your goal reached',
        'milestone_body'       => ':sales out of :goal in :month. :remaining to go in :days days.',
        'milestone_done_title' => ':month goal reached!',
        'milestone_done_body'  => 'Well done: :sales in sales for a :goal goal.',

        'pace_title' => 'Your pace is below your goal',
        'pace_body'  => 'Projection: :projection for a :goal goal. You need :required per day for :days days (currently :current per day).',

        'weekly_title'   => 'Your week at a glance',
        'weekly_body'    => 'Last 7 days: :week_sales (:orders orders).',
        'weekly_goal'    => ':month goal: :pct% reached, projection :projection.',
        'weekly_no_goal' => 'Set a goal to follow your pace.',

        'recap_title'    => ':month recap',
        'recap_hit'      => '{1} Goal reached: :sales for :goal (:pct%). Net earnings: :net. Your first streak has started!|[2,*] Goal reached: :sales for :goal (:pct%). Net earnings: :net. Streak: :streak months in a row.|[0] Goal reached: :sales for :goal (:pct%). Net earnings: :net.',
        'recap_missed'   => ':sales out of :goal (:pct%). Net earnings: :net. A new month starts: adjust your goal.',
        'recap_no_goal'  => 'Sales: :sales, net earnings: :net.',

        'new_goal_title'     => 'New month, new goal',
        'new_goal_body'      => 'Set your :month goal to follow your pace day by day.',
        'new_goal_suggested' => 'Set your :month goal. Suggested from your recent months: :suggested.',
    ],
    'export' => [
        'title'      => 'Profit Center — monthly report',
        'gross'      => 'Gross sales',
        'refunds'    => 'Refunded returns',
        'commission' => 'Choose’Tounsi commission',
        'shipping'   => 'Shipping you pay',
        'ads'        => 'Ads (wallet)',
        'ads_credit' => 'Ads (free credit, not deducted)',
        'net'        => 'Net earnings',
        'sales'      => 'Confirmed sales',
        'delivered'  => 'Of which delivered',
        'orders'     => 'Confirmed orders',
        'goal'       => 'Goal',
        'achieved'   => 'Achieved',
        'col' => [
            'order' => 'Order', 'date' => 'Date', 'status' => 'Status', 'amount' => 'Amount (DT)',
            'commission' => 'Commission (DT)', 'shipping' => 'Shipping (DT)', 'net' => 'Net (DT)', 'payout' => 'Payout',
        ],
        'status' => [
            'pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Ready', 'out_for_delivery' => 'Out for delivery',
            'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded',
        ],
    ],
];
