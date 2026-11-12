<?php

// Seller notifications about promotions created by Choose'Tounsi (App\Notifications\Sellers\AdminPromotionNotification)
return [
    'greeting'  => 'Bonjour :name,',
    // Sent once to the sellers whose products offered free delivery (pricing:notify-free-delivery-sellers)
    'free_delivery_everywhere' => [
        'title' => 'La livraison est désormais gratuite sur toute la plateforme',
        'body' => 'La livraison est désormais gratuite sur toute la plateforme et incluse dans le prix affiché au client. Vous continuez à recevoir votre prix moins la commission.',
        'detail' => 'L\'option « livraison gratuite » de vos produits n\'existe plus : plus rien n\'est déduit de vos gains pour la livraison. Vérifiez simplement que la taille du colis de chaque produit est correcte.',
        'cta' => 'Voir mes produits',
    ],
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
