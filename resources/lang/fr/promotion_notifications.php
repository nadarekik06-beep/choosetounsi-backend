<?php

// Seller notifications about promotions created by Choose'Tounsi (App\Notifications\Sellers\AdminPromotionNotification)
return [
    'greeting'  => 'Bonjour :name,',
    'details'   => 'Réduction : :discount · du :starts au :ends.',
    'funding'   => 'Comme pour vos propres promotions, vos gains et la commission sont calculés sur le prix réduit.',
    'read_only' => "Les promotions créées par Choose'Tounsi apparaissent dans votre tableau de bord (Promotions) en lecture seule.",
    'cta'       => 'Voir mes promotions',

    'created' => [
        'title' => "Nouvelle promotion Choose'Tounsi : :name",
        'body'  => "Choose'Tounsi a lancé la promotion « :name » (:discount) sur : :products.",
    ],
    'updated' => [
        'title' => "Promotion Choose'Tounsi modifiée : :name",
        'body'  => "Choose'Tounsi a modifié la promotion « :name » (:discount) sur : :products.",
    ],
    'paused' => [
        'title' => "Promotion Choose'Tounsi en pause : :name",
        'body'  => "La promotion « :name » est en pause : :products sont revenus à leur prix normal.",
    ],
    'resumed' => [
        'title' => "Promotion Choose'Tounsi reprise : :name",
        'body'  => "La promotion « :name » (:discount) a repris sur : :products.",
    ],
    'ended' => [
        'title' => "Promotion Choose'Tounsi terminée : :name",
        'body'  => "Choose'Tounsi a mis fin à la promotion « :name » : :products sont revenus à leur prix normal.",
    ],
    'replaced' => [
        'title' => "Votre promotion « :name » a été remplacée",
        'body'  => "Choose'Tounsi a placé :products dans sa propre promotion : ils ne font plus partie de votre promotion « :name ».",
    ],
];
