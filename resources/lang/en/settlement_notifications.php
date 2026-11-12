<?php

// Seller notification when a settlement is paid with a proof of payment (App\Notifications\Sellers\SettlementPaidNotification)
return [
    'greeting' => 'Hello :name,',
    'paid' => [
        'title'     => 'Your payment has been sent',
        'body'      => 'Your payment of :amount DT has been made. The receipt is available in your seller dashboard.',
        'reference' => 'Settlement: :reference',
        'cta'       => 'View my receipt',
    ],
];
