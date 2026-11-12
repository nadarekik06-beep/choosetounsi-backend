<?php

// Seller notification when a settlement is paid with a proof of payment (App\Notifications\Sellers\SettlementPaidNotification)
return [
    'greeting' => 'Bonjour :name,',
    'paid' => [
        'title'     => 'Votre paiement a été effectué',
        'body'      => 'Votre paiement de :amount DT a été effectué. Le reçu est disponible dans votre espace vendeur.',
        'reference' => 'Règlement : :reference',
        'cta'       => 'Voir mon reçu',
    ],
];
