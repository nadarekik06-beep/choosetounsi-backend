<?php

/*
|--------------------------------------------------------------------------
| Seller order notifications (e-mail + dashboard bell)
|--------------------------------------------------------------------------
|
| Transactional: always sent, no unsubscribe. Queued on their own queue so a
| large marketing digest never delays them:
|   php artisan queue:work --queue=notifications,default
*/

return [

    'queue' => env('SELLER_NOTIFICATIONS_QUEUE', 'notifications'),

    // Optional absolute URL of the logo shown at the top of the e-mails
    // (PNG, ~180×40). Without it the text wordmark is used.
    'logo_url' => env('MAIL_LOGO_URL'),

    // Order dates in the e-mails (the app itself runs in UTC).
    'timezone' => env('SELLER_NOTIFICATIONS_TIMEZONE', 'Africa/Tunis'),

    // Reminder when a confirmed sub-order is still not ready for pickup.
    'pickup_reminder' => [
        'enabled' => (bool) env('SELLER_PICKUP_REMINDER', false),
        'hours'   => (int) env('SELLER_PICKUP_REMINDER_HOURS', 24),
    ],
];
