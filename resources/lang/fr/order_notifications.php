<?php

// Notifications vendeur par sous-commande : e-mail + cloche du tableau de bord.
// App\Notifications\Orders\*  ·  resources/views/emails/seller-orders/*

return [
    'money' => ':amount DT',

    'common' => [
        'greeting'      => 'Bonjour :name,',
        'greeting_anon' => 'Bonjour,',
        'reference'     => 'Référence de la commande',
        'order_date'    => 'Commandé le',
        'buyer'         => 'Client',
        'items'         => 'Articles',
        'qty'           => 'Qté',
        'items_total'   => 'Total des articles',
        'discount'      => 'Votre coupon',
        'commission'    => "Commission Choose'Tounsi",
        'shipping'      => 'Livraison à votre charge',
        'net'           => 'Votre gain net',
        'view_order'    => 'Voir la commande',
        'reason'        => "Vous recevez cet e-mail car vous vendez sur Choose'Tounsi et il concerne une commande de votre boutique.",
        'help'          => 'Une question ? Écrivez-nous à :email.',
    ],

    'pickup' => [
        'heading'    => 'Adresse d’enlèvement',
        'incomplete' => 'Votre adresse d’enlèvement est incomplète (manquant : :fields). Le livreur ne pourra pas récupérer le colis tant qu’elle n’est pas complétée.',
        'fix'        => 'Compléter mon adresse d’enlèvement',
    ],

    'pickup_fields' => [
        'shop_name'   => 'nom de la boutique',
        'contact'     => 'personne à contacter',
        'phone'       => 'téléphone valide',
        'address'     => 'adresse',
        'city'        => 'ville / délégation',
        'postal_code' => 'code postal',
        'wilaya'      => 'gouvernorat',
        'zone'        => 'zone (délégation / secteur)',
        'gps'         => 'position sur la carte',
    ],

    'tips' => [
        'heading' => 'Conseils d’emballage',
        'list'    => [
            'Emballez tous les articles dans un colis solide et bien fermé (scotchez toutes les ouvertures).',
            'Écrivez lisiblement la référence :ref sur le colis.',
            'Protégez les articles fragiles avec du papier bulle ou du papier.',
            'Gardez le colis prêt à votre adresse d’enlèvement — le livreur appelle avant de passer.',
        ],
    ],

    'placed' => [
        'subject'   => 'Nouvelle commande :ref — :count article|Nouvelle commande :ref — :count articles',
        'title'     => 'Nouvelle commande :ref',
        'body'      => ':count article · en attente de confirmation|:count articles · en attente de confirmation',
        'headline'  => 'Vous avez une nouvelle commande',
        'intro'     => "En attente de confirmation par Choose'Tounsi — n’expédiez rien pour l’instant.",
    ],

    'confirmed' => [
        'subject'   => 'Commande :ref confirmée — préparez le colis|Commande :ref confirmée — préparez le colis',
        'title'     => 'Commande :ref confirmée — à préparer',
        'body'      => ':count article à emballer · un livreur viendra le récupérer|:count articles à emballer · un livreur viendra les récupérer',
        'headline'  => 'Préparez le colis',
        'intro'     => 'La commande est confirmée. Un livreur viendra récupérer le colis à votre adresse d’enlèvement.',
        'ref_hint'  => 'Écrivez cette référence sur le colis',
        'next'      => 'Quand le colis est prêt, passez la commande en « Terminée » dans votre tableau de bord.',
    ],

    'cancelled' => [
        'subject'   => 'Commande :ref annulée',
        'title'     => 'Commande :ref annulée',
        'body'      => 'Ne la préparez pas et ne l’expédiez pas.|Ne la préparez pas et ne l’expédiez pas.',
        'headline'  => 'Commande annulée',
        'intro'     => 'Cette commande a été annulée. Merci de ne pas la préparer ni l’expédier.',
        'items'     => 'Produits annulés',
    ],

    'pickup_reminder' => [
        'subject'   => 'Rappel : la commande :ref attend d’être préparée|Rappel : la commande :ref attend d’être préparée',
        'title'     => 'La commande :ref attend d’être préparée',
        'body'      => 'Confirmée mais pas encore prête pour l’enlèvement.|Confirmée mais pas encore prête pour l’enlèvement.',
        'headline'  => 'Votre commande attend',
        'intro'     => 'Cette commande est confirmée mais n’est pas encore marquée prête pour l’enlèvement. Merci de préparer le colis pour que le client le reçoive rapidement.',
        'ref_hint'  => 'Écrivez cette référence sur le colis',
        'next'      => 'Quand le colis est prêt, passez la commande en « Terminée » dans votre tableau de bord.',
    ],
    // Mises à jour du transporteur (Intigo) : cloche du tableau de bord
    'parcel_out_for_delivery' => [
        'title' => 'Colis :ref en cours de livraison',
        'body'  => 'Le livreur remet aujourd\'hui votre colis (:count article) au client.|Le livreur remet aujourd\'hui votre colis (:count articles) au client.',
    ],
    'parcel_delivered' => [
        'title' => 'Colis :ref livré',
        'body'  => 'Livré et payé par le client (:count article). Vos gains sont en route.|Livré et payé par le client (:count articles). Vos gains sont en route.',
    ],
    'parcel_returned' => [
        'title' => 'Colis :ref retourné',
        'body'  => 'Le client n\'a pas pris le colis (:count article) : il est revenu à votre adresse de ramassage et son stock est rétabli.|Le client n\'a pas pris le colis (:count articles) : il est revenu à votre adresse de ramassage et son stock est rétabli.',
    ],
];
