<?php

/*
|--------------------------------------------------------------------------
| In-app (bell) + e-mail notifications
|--------------------------------------------------------------------------
|
| Every database notification is stored with one payload contract
| (App\Notifications\Support\Payload): type, category, audience, title,
| body, link, icon, action, data.
|
| Categories decide which channels a user may turn off. "locked" channels
| are always on (transactional / security messages).
|
*/

return [

    'queue' => env('NOTIFICATIONS_QUEUE', 'default'),

    'categories' => [
        // Transactional: the bell can't be turned off, e-mail is on by default.
        'orders'     => ['in_app' => 'locked', 'email' => 'default_on'],
        'payments'   => ['in_app' => 'locked', 'email' => 'default_on'],
        'complaints' => ['in_app' => 'locked', 'email' => 'default_on'],
        // Reminders: both channels can be turned off.
        'reviews'    => ['in_app' => 'default_on', 'email' => 'default_on'],
        // Marketing: bell can be turned off, e-mail is opt-in (users.marketing_emails_opt_in).
        'promotions' => ['in_app' => 'default_on', 'email' => 'opt_in'],
        // Security: always on.
        'account'    => ['in_app' => 'locked', 'email' => 'locked'],
    ],

    // Days after delivery before the "rate your purchase" reminder (bell + e-mail).
    'review_prompt_delay_days' => (int) env('NOTIFICATIONS_REVIEW_DELAY_DAYS', 3),
    // Prompts older than delay + this window are never reminded (no blast of old orders).
    'review_prompt_window_days' => 7,

    // Promotions: at most this many promotional notifications per user per day.
    'promotions_daily_cap' => 1,

    // A favourited product's price drop is announced from this decrease on (percent).
    'price_drop_min_percent' => 5,

    // "Your coupon expires soon": hours before expires_at.
    'coupon_expiry_hours' => 48,

    'timezone' => env('NOTIFICATIONS_TIMEZONE', 'Africa/Tunis'),
];
