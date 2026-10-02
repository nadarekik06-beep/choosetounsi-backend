<?php

/*
|--------------------------------------------------------------------------
| AI product description generator (seller dashboard)
|--------------------------------------------------------------------------
| Runs on the Groq free tier through App\Services\Chat\GroqClient, so it shares
| the platform-wide request budget (services.groq.daily_budget) with the
| shopping chatbot, translations and ad copy. `reserve_calls` keeps part of that
| budget for those features: descriptions stop before the chatbot starves.
|
| Levels are resolved from the seller's plan by DescriptionPolicy:
|   free  → plan without the `ai_tools` feature (Green Pepper)
|   red   → plan with `ai_tools`
|   black → plan with `ai_tools` on the Black tier
*/

return [

    'model'          => env('AI_DESC_MODEL', env('GROQ_MODEL', 'openai/gpt-oss-120b')),
    // Tried once when the main model is rate limited / down (separate Groq quota).
    'fallback_model' => env('AI_DESC_FALLBACK_MODEL', 'openai/gpt-oss-20b'),
    'timeout'        => (int) env('AI_DESC_TIMEOUT', 45),
    // "medium" writes slightly cleaner French/Arabic but needs ~3x the completion budget,
    // which does not fit the free tier's 8K tokens/minute — keep "low" on the free tier
    'reasoning_effort' => env('AI_DESC_REASONING', 'low'),
    'reserve_calls'  => (int) env('AI_DESC_RESERVE_CALLS', 150),
    'timezone'       => 'Africa/Tunis',

    'tones'     => ['professional', 'friendly', 'luxury', 'promo', 'artisanal'],
    'languages' => ['fr', 'ar', 'en'],
    'lengths'   => ['short', 'medium', 'long'],

    // Old dashboard tone keys → new ones (keeps POST /seller/ai/quick-description compatible).
    'legacy_tones' => [
        'casual'        => 'friendly',
        'exciting'      => 'promo',
        'trust-focused' => 'professional',
    ],

    'levels' => [
        'free' => [
            'daily_limit'    => (int) env('AI_DESC_LIMIT_FREE', 5),
            'tones'          => ['professional', 'friendly'],
            'any_language'   => false,   // only the seller's dashboard language
            'multi_language' => false,
            'max_variants'   => 1,
            'extras'         => false,   // SEO title, tags, social caption
            'brand_voice'    => false,
        ],
        'red' => [
            'daily_limit'    => (int) env('AI_DESC_LIMIT_RED', 40),
            'tones'          => ['professional', 'friendly', 'luxury', 'promo', 'artisanal'],
            'any_language'   => true,
            'multi_language' => false,
            'max_variants'   => 3,
            'extras'         => true,
            'brand_voice'    => false,
        ],
        'black' => [
            'daily_limit'    => (int) env('AI_DESC_LIMIT_BLACK', 150),
            'tones'          => ['professional', 'friendly', 'luxury', 'promo', 'artisanal'],
            'any_language'   => true,
            'multi_language' => true,    // FR + AR + EN in one click, each written natively
            'max_variants'   => 3,
            'extras'         => true,
            'brand_voice'    => true,    // saved store style + keywords applied to every generation
        ],
    ],

    /*
    | Tunisian commercial calendar, used to suggest a fitting occasion when it is
    | close. Religious dates follow the lunar calendar and are approximate (±1 day):
    | update the `dated` list once a year. `yearly` entries are month-day ranges.
    */
    'calendar' => [
        'dated' => [
            ['key' => 'ramadan',  'from' => '2027-02-08', 'to' => '2027-03-09'],
            ['key' => 'aid_fitr', 'from' => '2027-03-10', 'to' => '2027-03-12'],
            ['key' => 'aid_adha', 'from' => '2027-05-16', 'to' => '2027-05-19'],
            ['key' => 'ramadan',  'from' => '2028-01-28', 'to' => '2028-02-25'],
            ['key' => 'aid_fitr', 'from' => '2028-02-26', 'to' => '2028-02-28'],
            ['key' => 'aid_adha', 'from' => '2028-05-04', 'to' => '2028-05-07'],
        ],
        'yearly' => [
            ['key' => 'summer',         'from' => '06-15', 'to' => '08-31'],
            ['key' => 'wedding_season', 'from' => '06-01', 'to' => '09-30'],
            ['key' => 'back_to_school', 'from' => '08-25', 'to' => '09-30'],
            ['key' => 'winter',         'from' => '12-01', 'to' => '02-28'],
            ['key' => 'new_year',       'from' => '12-15', 'to' => '12-31'],
            ['key' => 'winter_sales',   'from' => '02-01', 'to' => '03-10'],
            ['key' => 'summer_sales',   'from' => '08-01', 'to' => '09-10'],
        ],
        'lookahead_days' => 75,
    ],
];
