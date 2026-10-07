<?php

// Growth Radar (seller dashboard). Card texts are rendered from these templates;
// Groq may only rephrase the headline, never the numbers.
return [
    'cards' => [
        'leaking_product' => [
            'headline' => '{0} :product got :views views but no order in 30 days|{1} :product got :views views but only 1 order in 30 days|[2,*] :product got :views views but only :orders orders in 30 days',
            'recommendation' => [
                'photos'      => 'Add clear photos (at least 3, you have :images) — buyers leave when they cannot see the product well',
                'description' => 'Rewrite the description with the AI generator: materials, sizes, delivery',
                'price'       => 'Discount :pct% for :days days to bring it to the category price',
                'price_test'  => 'Test a :pct% discount for :days days and watch the orders',
                'listing'     => 'Complete the listing (score :score/100): details and a precise title',
                'stock'       => 'The product or some variants are out of stock: buyers cannot order — restock',
                'shipping'    => 'The delivery fee stops the order: lower it or offer a discount',
            ],
        ],
        'price_position' => [
            'headline' => [
                'high' => ':product is :above_pct% above the category median (:median DT)',
                'low'  => ':product sells well at :below_pct% under the category median (:median DT)',
            ],
            'recommendation' => [
                'high' => 'Discount :pct% for :days days (:new_price DT) and compare the orders',
                'low'  => 'Raise the price to :new_price DT',
            ],
        ],
        'hidden_demand' => [
            'headline' => 'Buyers searched “:query” :searches times and found almost nothing',
            'recommendation' => 'List a product that matches “:query”',
        ],
        'warm_audience' => [
            'headline' => ':audience buyers want :product but have not bought it',
            'recommendation' => 'Send them a private :pct% coupon, valid :days days — only they can use it',
        ],
        'seasonal' => [
            'headline' => ':event starts in :days_until days',
            'recommendation' => [
                'discount'   => 'Start a :pct% discount on :count product(s) on :start_date',
                'flash_sale' => 'Run a 48 h flash sale (-:pct%) on :count product(s) from :start_date',
            ],
        ],
        'dead_stock' => [
            'headline' => ':product has not sold in :days days (:stock in stock)',
            'recommendation' => [
                'clearance'  => 'Clear it: :pct% off for :promo_days days, or bundle it with a best-seller',
                'visibility' => 'Almost nobody sees it: boost it, or bundle it with a best-seller',
            ],
        ],
        'promo_timing' => [
            'headline' => 'Buyers are most active on :day around :hour:00',
            'recommendation' => 'Launch a :hours h flash sale (-:pct%) on :product on :start_date at :hour:00',
        ],
        'results' => [
            'headline' => [
                'win'     => 'Your :kind on :product worked',
                'loss'    => 'Your :kind on :product did not pay off',
                'neutral' => 'Your :kind on :product made no clear difference',
                'unclear' => 'We cannot tell yet if your :kind on :product worked',
            ],
        ],
    ],

    'kinds' => [
        'discount' => 'discount', 'flash_sale' => 'flash sale', 'coupon' => 'coupon', 'boost' => 'boost',
        'edit' => 'listing update', 'listing' => 'new listing', 'bundle' => 'bundle',
    ],

    'unclear' => [
        'short_history'  => 'The product was listed too recently to compare with the days before.',
        'overlap'        => 'Another promotion or boost ran during the comparison days.',
        'few_sales'      => 'Too few sales to tell a real change from chance.',
        'not_measurable' => 'This action has no sales window we can measure.',
    ],

    'basis' => [
        'own'       => 'Based on your own sales and visits.',
        'category'  => 'Compared with similar products in this category (at least 5 shops, no shop named).',
        'subcategory' => 'Compared with similar products in this subcategory (at least 5 shops, no shop named).',
        'platform'  => 'Compared with the whole marketplace (at least 5 shops).',
        'fallback'  => 'Not enough market data yet: compared with a typical rate, so treat it as a rough guide.',
        'measured'  => 'Uses the effect measured last time in this category.',
        'default'   => 'No measured effect yet for this event: conservative estimate.',
        'none'      => 'Not enough data to estimate the money impact yet.',
        'learned'   => 'Adjusted to how your buyers reacted to your past actions.',
    ],

    'notify' => [
        'title' => '{1} New Growth Radar action: up to +:high DT|[2,*] :count new Growth Radar actions, up to +:high DT',
        'body'  => ':headline',
        'subject' => 'Growth Radar: a new action for your shop',
        'cta'   => 'Open Growth Radar',
        'footer' => 'You receive this because you have Black Pepper. At most one e-mail a week.',
        'result_title' => 'Results are in for your :kind',
        'result_body'  => ':headline',
    ],

    'coupon' => [
        'title'     => ':discount on :product, just for you',
        'body'      => 'Use code :code at checkout — a private offer from :shop.',
        'subject'   => 'A private offer on :product',
        'preheader' => ':discount, reserved for you.',
        'intro'     => ':shop noticed you liked :product. Here is a code only you can use:',
        'expires'   => 'Valid until :date.',
    ],

    'errors' => [
        'audience_too_small' => 'Not enough interested buyers right now (at least :min are needed so no one can be singled out). Try again later.',
        'audience_product'   => 'A targeted coupon must include the product of the card.',
        'refresh_cooldown'   => 'Growth Radar was refreshed a few minutes ago. Try again in :minutes min.',
    ],
];
