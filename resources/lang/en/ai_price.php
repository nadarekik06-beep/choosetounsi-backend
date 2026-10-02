<?php

// Price optimizer — texts of the fallback calculation (when Groq doesn't answer).
return [
    'strategy_entry'   => 'Competitive entry pricing',
    'strategy_market'  => 'Market-aligned pricing',
    'reason_market'    => 'Based on :count real Tunisian market prices from :platforms (average: :avg TND)',
    'reason_platform'  => 'Based on :count ChooseTounsi listings in this category (average: :avg TND)',
    'reason_none'      => 'No market data available — recommendation based on the current price for the :category category',
    'reason_current'   => 'Your current price of :price TND is :positioning.',
    'positioning'      => ['underpriced' => 'below the market', 'competitive' => 'competitive', 'overpriced' => 'above the market', 'unknown' => 'hard to place'],
    'impact_entry'     => 'A competitive entry price should attract first buyers on ChooseTounsi.',
    'impact_market'    => 'Aligning with market pricing maintains conversion while optimizing revenue.',
    'competitors_market'   => ':platforms: :count listings between :min and :max TND.',
    'competitors_platform' => 'ChooseTounsi shows :count competitors (average :avg TND).',
    'competitors_none'     => 'No competitor data found.',
    'overpriced'       => 'Your price is :pct % above the market average — consider reducing it to improve conversion.',
    'room_to_increase' => 'Your price is :pct % below the market — you may have room to increase it.',
    'no_sales'         => 'No sales yet — make sure the listing has complete photos.',
    'psycho'           => 'Use :psycho TND instead of :price TND — charm pricing converts better.',
];
