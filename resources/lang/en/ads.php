<?php

return [
    'errors' => [
        'not_ready'             => 'This product isn\'t ready to be boosted yet. Fix the blocking issues first.',
        'already_open'          => 'This product already has a campaign. Edit, pause or cancel it instead.',
        'cpc_below_min'         => 'The maximum cost per click must be at least :min DT for this category.',
        'budget_below_min'      => 'The daily budget must be at least :min DT.',
        'budget_below_cpc'      => 'The daily budget must cover at least one click (:min DT).',
        'total_below_daily'     => 'The total budget can\'t be lower than the daily budget.',
        'wallet_too_low'        => 'Your ad wallet needs at least :required DT (available: :available DT). Top it up first.',
        'invalid_amount'        => 'Invalid amount.',
        'adjust_below_zero'     => 'This adjustment would make the wallet negative.',
        'invalid_end_date'      => 'The end date must be in the future.',
        'invalid_price_range'   => 'The maximum target price can\'t be lower than the minimum.',
        'invalid_placements'    => 'Unknown placement.',
        'not_open'              => 'This campaign has already ended.',
        'not_active'            => 'Only running campaigns can be paused.',
        'not_paused'            => 'This campaign isn\'t paused.',
        'paused_by_admin'       => 'This campaign was paused by the Choose\'Tounsi team. Contact support to resume it.',
        'paused_by_plan'        => 'This campaign was paused because your plan no longer includes sponsoring. It resumes when you upgrade.',
        'budget_exhausted_today' => 'Today\'s budget is spent. The campaign resumes automatically tomorrow.',
        'campaign_ended'        => 'This campaign\'s end date has passed.',
        'product_unavailable'   => 'The product is inactive or out of stock.',
        'top_up_below_min'      => 'The minimum top-up is :min DT.',
        'gateway_unavailable'   => 'This payment method isn\'t available.',
        'top_up_not_pending'    => 'This top-up has already been settled.',
        'not_found'             => 'Campaign not found.',
        'product_not_found'     => 'Product not found.',
    ],

    'notif' => [
        'activated' => [
            'title' => 'Campaign live: :name',
            'body'  => 'Your ad for ":name" is running — :budget DT/day, max :cpc DT per click.',
        ],
        'paused' => [
            'title'            => 'Campaign paused: :name',
            'wallet_empty'     => 'Your ad wallet is empty. Top it up to resume the campaign.',
            'out_of_stock'     => '":name" is out of stock. The campaign resumes when you restock.',
            'product_inactive' => '":name" is no longer active, so its campaign is paused.',
            'plan_downgrade'   => 'Your plan no longer includes sponsoring. The campaign resumes if you upgrade.',
            'admin'            => 'The Choose\'Tounsi team paused this campaign. Check your messages or contact support.',
        ],
        'ended' => [
            'completed' => ['title' => 'Campaign finished: :name', 'body' => 'Spent :spend DT · :clicks clicks · :orders orders · :revenue DT revenue.'],
            'cancelled' => ['title' => 'Campaign cancelled: :name', 'body' => 'Spent :spend DT · :clicks clicks · :orders orders · :revenue DT revenue.'],
            'rejected'  => ['title' => 'Campaign rejected: :name', 'body' => 'Reason: :reason. :refund DT has been refunded to your ad wallet.'],
        ],
        'view' => 'View campaign',
        'roas' => 'Return on ad spend: :roas×',
    ],
];
