<?php

return [
    'shop_name' => 'your shop',
    'levels'    => ['high' => 'high', 'medium' => 'medium', 'low' => 'low', 'none' => 'none'],

    'ai' => [
        'insufficient'  => 'Not enough data yet to forecast sales of ":name": no sales and too few comparable products on the marketplace.',
        'insufficient_tip' => 'Work on visibility (photos, description, price): forecasts will appear with the first orders.',
        'category'      => 'Estimate based on similar products in the category: between :low and :high units for ":name" over the next 4 weeks. The range will narrow as soon as you make your first sales.',
        'forecast'      => '":name" should sell between :low and :high units over the next 4 weeks (:rev_low to :rev_high TND).',
        'reliability'   => 'Reliability :level (:orders orders over :days days).',
        'stockout'      => 'At the forecast pace, stock runs out around :date: reorder :qty units before :by.',
        'stock_ok'      => 'Your current stock (:stock units) covers the coming weeks.',
        'event'         => ':event starts in :days days.',
        'event_effect'  => 'Last year, sales in your category changed by :pct % during this period.',
    ],

    'notif' => [
        'stockout' => [
            'title'   => 'Stock-out expected: :name',
            'body'    => 'Stock runs out in about :days days (:date). Reorder :qty units before :by.',
            'subject' => 'Stock-out expected in :days days — :name',
        ],
        'event' => [
            'title'   => ':event in :days days',
            'body'    => 'Prepare your stock and schedule a promotion for the products concerned.',
            'body_effect' => 'Last year: :pct % sales in your category. Prepare your stock and schedule a promotion.',
            'subject' => ':event starts in :days days — get your shop ready',
        ],
        'sales_drop' => [
            'title'   => 'Sales dropping: :name',
            'body'    => ':actual sold in 14 days while we expected at least :low. Check the price and photos, or run a promotion.',
            'subject' => 'Sales lower than forecast — :name',
        ],
        'cta'     => 'View the forecast',
        'footer'  => 'You receive this e-mail because forecast alerts are on. You can turn them off in AI tools → Sales → Settings.',
    ],

    'digest' => [
        'subject'       => 'Your week in forecasts — :shop',
        'headline'      => 'The next 4 weeks',
        'next28'        => 'Expected sales: :low to :high units (:rev_low to :rev_high TND).',
        'next28_none'   => 'Not enough sales yet for a shop forecast.',
        'at_risk'       => 'Products to restock',
        'at_risk_row'   => ':name — stock-out in :days days',
        'events'        => 'Coming up',
        'event_row'     => ':event — in :days days',
        'accuracy'      => '4 weeks ago we forecast :low to :high units; you sold :actual.',
        'nothing'       => 'Nothing urgent this week.',
        'title'         => 'Your weekly forecast summary',
        'body'          => ':low to :high units expected over 4 weeks · :risk product(s) to restock',
        'footer'        => 'Weekly summary turned on in AI tools → Sales → Settings.',
    ],

    'refresh_wait' => 'Forecast refreshed recently. Try again in :minutes min.',
];
