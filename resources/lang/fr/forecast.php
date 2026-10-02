<?php

return [
    'shop_name' => 'votre boutique',
    'levels'    => ['high' => 'élevée', 'medium' => 'moyenne', 'low' => 'faible', 'none' => 'nulle'],

    // Text used when Groq is unavailable — built from the same computed numbers.
    'ai' => [
        'insufficient'  => 'Pas encore assez de données pour prévoir les ventes de « :name » : aucune vente et trop peu de produits comparables sur la marketplace.',
        'insufficient_tip' => 'Travaillez la visibilité (photos, description, prix) : les prévisions apparaîtront dès les premières commandes.',
        'category'      => 'Estimation basée sur des produits similaires de la catégorie : entre :low et :high unités pour « :name » sur les 4 prochaines semaines. Cette fourchette s\'affinera dès vos premières ventes.',
        'forecast'      => '« :name » devrait se vendre entre :low et :high unités sur les 4 prochaines semaines (:rev_low à :rev_high DT).',
        'reliability'   => 'Fiabilité :level (:orders commandes sur :days jours).',
        'stockout'      => 'Au rythme prévu, le stock sera épuisé vers le :date : réapprovisionnez :qty unités avant le :by.',
        'stock_ok'      => 'Votre stock actuel (:stock unités) couvre les prochaines semaines.',
        'event'         => ':event commence dans :days jours.',
        'event_effect'  => 'L\'an dernier, :pct % de ventes dans votre catégorie pendant cette période.',
    ],

    'notif' => [
        'stockout' => [
            'title'   => 'Rupture prévue : :name',
            'body'    => 'Stock épuisé dans environ :days jours (:date). Réapprovisionnez :qty unités avant le :by.',
            'subject' => 'Rupture de stock prévue dans :days jours — :name',
        ],
        'event' => [
            'title'   => ':event dans :days jours',
            'body'    => 'Préparez votre stock et programmez une promotion pour vos produits concernés.',
            'body_effect' => 'L\'an dernier : :pct % de ventes dans votre catégorie. Préparez votre stock et programmez une promotion.',
            'subject' => ':event commence dans :days jours — préparez votre boutique',
        ],
        'sales_drop' => [
            'title'   => 'Ventes en baisse : :name',
            'body'    => ':actual vendus en 14 jours alors que nous en attendions au moins :low. Vérifiez le prix, les photos ou lancez une promotion.',
            'subject' => 'Ventes plus faibles que prévu — :name',
        ],
        'cta'     => 'Voir la prévision',
        'footer'  => 'Vous recevez cet e-mail car les alertes de prévision sont activées. Vous pouvez les désactiver dans Outils IA → Ventes → Réglages.',
    ],

    'digest' => [
        'subject'       => 'Votre semaine en prévisions — :shop',
        'headline'      => 'Les 4 prochaines semaines',
        'next28'        => 'Ventes prévues : :low à :high unités (:rev_low à :rev_high DT).',
        'next28_none'   => 'Pas encore assez de ventes pour une prévision de la boutique.',
        'at_risk'       => 'Produits à réapprovisionner',
        'at_risk_row'   => ':name — rupture dans :days jours',
        'events'        => 'À venir',
        'event_row'     => ':event — dans :days jours',
        'accuracy'      => 'Il y a 4 semaines, nous prévoyions :low à :high unités ; vous en avez vendu :actual.',
        'nothing'       => 'Rien d\'urgent cette semaine.',
        'title'         => 'Votre résumé hebdomadaire des prévisions',
        'body'          => ':low à :high unités prévues sur 4 semaines · :risk produit(s) à réapprovisionner',
        'footer'        => 'Résumé hebdomadaire activé dans Outils IA → Ventes → Réglages.',
    ],

    'refresh_wait' => 'Prévision déjà actualisée récemment. Réessayez dans :minutes min.',
];
