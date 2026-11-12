<?php

// Seller notification when a settlement is paid with a proof of payment (App\Notifications\Sellers\SettlementPaidNotification)
return [
    'greeting' => 'مرحباً :name،',
    'paid' => [
        'title'     => 'تم إرسال دفعتك',
        'body'      => 'تم دفع مبلغ :amount دينار لك. الإيصال متوفر في فضاء البائع الخاص بك.',
        'reference' => 'التسوية: :reference',
        'cta'       => 'عرض الإيصال',
    ],
];
