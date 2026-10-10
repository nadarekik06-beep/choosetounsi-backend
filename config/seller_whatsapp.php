<?php

/*
|--------------------------------------------------------------------------
| Seller WhatsApp notifications (manual, free)
|--------------------------------------------------------------------------
|
| When the admin confirms an order, each seller is asked on WhatsApp to
| prepare it. No API: the admin panel opens a wa.me link with the message
| pre-filled and the admin sends it from the business WhatsApp. Reminders
| show up in the admin panel when due (php artisan orders:whatsapp-reminders,
| every 5 minutes). Message texts: App\Services\Orders\WhatsApp\SellerWhatsAppMessages.
*/

return [

    // Business WhatsApp the messages are sent from (shown to sellers in their settings)
    'business_number' => env('WHATSAPP_BUSINESS_NUMBER', '21657252576'),

    'timezone' => env('SELLER_WHATSAPP_TIMEZONE', 'Africa/Tunis'),

    // Reminders only become due inside working hours (Tunis time)
    'working_hours' => [
        'start' => env('SELLER_WHATSAPP_WORK_START', '08:00'),
        'end'   => env('SELLER_WHATSAPP_WORK_END', '20:00'),
    ],

    // A reminder that would fall outside working hours moves to this time the next morning
    'morning_time' => env('SELLER_WHATSAPP_MORNING_TIME', '08:30'),

    // Working-hours delays (hours)
    'delays' => [
        'reminder_1' => (int) env('SELLER_WHATSAPP_REMINDER_1_HOURS', 2),  // after the confirmation
        'reminder_2' => (int) env('SELLER_WHATSAPP_REMINDER_2_HOURS', 4),  // after reminder 1 was sent
        'overdue'    => (int) env('SELLER_WHATSAPP_OVERDUE_HOURS', 4),     // after reminder 2 was sent
    ],
];
