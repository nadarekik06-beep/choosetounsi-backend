<?php

return [
    'invalid_tone'  => 'Unknown tone.',
    'name_required' => 'Give the product a name before generating a description.',
    'daily_limit'   => 'You have used your :limit AI descriptions for today. Your quota resets at midnight.',
    'rate_limited'  => 'The AI is handling a lot of requests right now. Try again in :seconds seconds — nothing was counted.',
    'busy_today'    => 'The AI has reached its free capacity for today. Try again tomorrow — nothing was counted.',
    'unavailable'   => 'The AI service is unavailable right now. Try again in a moment — nothing was counted.',
    'bad_output'    => 'The AI returned an unusable answer. Try again — nothing was counted.',
    'voice_saved'   => 'Store voice saved. It will be used for every new description.',
    'locked' => [
        'tone'           => 'This tone is available with :plan.',
        'language'       => 'Writing in another language than your dashboard language is available with :plan.',
        'multi_language' => 'Generating in several languages at once is available with :plan.',
        'variants'       => 'Several variants per request are available with :plan.',
        'brand_voice'    => 'Store voice is available with :plan.',
    ],
];
