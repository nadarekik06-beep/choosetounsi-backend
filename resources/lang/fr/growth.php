<?php

// Radar de croissance / Growth Radar (tableau de bord vendeur). Les textes des cartes viennent de ces
// modèles ; Groq peut seulement reformuler le titre, jamais les chiffres.
return [
    'cards' => [
        'leaking_product' => [
            'headline' => '{0} :product a eu :views vues mais aucune commande en 30 jours|{1} :product a eu :views vues mais une seule commande en 30 jours|[2,*] :product a eu :views vues mais seulement :orders commandes en 30 jours',
            'recommendation' => [
                'photos'      => 'Ajoutez des photos nettes (au moins 3, vous en avez :images) — les acheteurs partent quand ils voient mal le produit',
                'description' => 'Réécrivez la description avec le générateur IA : matières, tailles, livraison',
                'price'       => 'Remise de :pct% pendant :days jours pour revenir au prix de la catégorie',
                'price_test'  => 'Testez une remise de :pct% pendant :days jours et suivez les commandes',
                'listing'     => 'Complétez la fiche (score :score/100) : caractéristiques et titre précis',
                'stock'       => 'Le produit ou certaines variantes sont en rupture : les acheteurs ne peuvent pas commander — réapprovisionnez',
                'shipping'    => 'Les frais de livraison freinent la commande : revoyez-les ou offrez une remise',
            ],
        ],
        'price_position' => [
            'headline' => [
                'high' => ':product est :above_pct% au-dessus du prix médian de la catégorie (:median DT)',
                'low'  => ':product se vend bien à :below_pct% sous le prix médian de la catégorie (:median DT)',
            ],
            'recommendation' => [
                'high' => 'Remise de :pct% pendant :days jours (:new_price DT), puis comparez les commandes',
                'low'  => 'Montez le prix à :new_price DT',
            ],
        ],
        'hidden_demand' => [
            'headline' => 'Des acheteurs ont cherché « :query » :searches fois sans presque rien trouver',
            'recommendation' => 'Mettez en vente un produit qui correspond à « :query »',
        ],
        'warm_audience' => [
            'headline' => ':audience acheteurs veulent :product mais ne l\'ont pas acheté',
            'recommendation' => 'Envoyez-leur un code privé de :pct%, valable :days jours — eux seuls peuvent l\'utiliser',
        ],
        'seasonal' => [
            'headline' => ':event commence dans :days_until jours',
            'recommendation' => [
                'discount'   => 'Lancez une remise de :pct% sur :count produit(s) le :start_date',
                'flash_sale' => 'Lancez une vente flash de 48 h (-:pct%) sur :count produit(s) à partir du :start_date',
            ],
        ],
        'dead_stock' => [
            'headline' => ':product ne s\'est pas vendu depuis :days jours (:stock en stock)',
            'recommendation' => [
                'clearance'  => 'Écoulez-le : -:pct% pendant :promo_days jours, ou proposez-le en pack avec un best-seller',
                'visibility' => 'Presque personne ne le voit : sponsorisez-le, ou proposez-le en pack avec un best-seller',
            ],
        ],
        'promo_timing' => [
            'headline' => 'Les acheteurs sont les plus actifs le :day vers :hour h',
            'recommendation' => 'Lancez une vente flash de :hours h (-:pct%) sur :product le :start_date à :hour h',
        ],
        'results' => [
            'headline' => [
                'win'     => 'Votre :kind sur :product a fonctionné',
                'loss'    => 'Votre :kind sur :product n\'a pas été rentable',
                'neutral' => 'Votre :kind sur :product n\'a pas fait de différence nette',
                'unclear' => 'Impossible de dire pour l\'instant si votre :kind sur :product a fonctionné',
            ],
        ],
    ],

    'kinds' => [
        'discount' => 'remise', 'flash_sale' => 'vente flash', 'coupon' => 'code promo', 'boost' => 'sponsorisation',
        'edit' => 'mise à jour de fiche', 'listing' => 'nouveau produit', 'bundle' => 'pack',
    ],

    'unclear' => [
        'short_history'  => 'Le produit est en ligne depuis trop peu de temps pour comparer avec les jours d\'avant.',
        'overlap'        => 'Une autre promotion ou sponsorisation tournait pendant les jours de comparaison.',
        'few_sales'      => 'Trop peu de ventes pour distinguer un vrai changement du hasard.',
        'not_measurable' => 'Cette action n\'a pas de période de ventes mesurable.',
    ],

    'basis' => [
        'own'       => 'Basé sur vos propres ventes et visites.',
        'category'  => 'Comparé aux produits similaires de la catégorie (au moins 5 boutiques, aucune nommée).',
        'subcategory' => 'Comparé aux produits similaires de la sous-catégorie (au moins 5 boutiques, aucune nommée).',
        'platform'  => 'Comparé à l\'ensemble de la marketplace (au moins 5 boutiques).',
        'fallback'  => 'Pas encore assez de données de marché : comparé à un taux typique, à prendre comme ordre de grandeur.',
        'measured'  => 'Utilise l\'effet mesuré la dernière fois dans cette catégorie.',
        'default'   => 'Pas encore d\'effet mesuré pour cet événement : estimation prudente.',
        'none'      => 'Pas encore assez de données pour estimer l\'impact en dinars.',
        'learned'   => 'Ajusté selon la réaction de vos acheteurs à vos actions passées.',
    ],

    'notify' => [
        'title' => '{1} Nouvelle action du Radar de croissance : jusqu\'à +:high DT|[2,*] :count nouvelles actions du Radar de croissance, jusqu\'à +:high DT',
        'body'  => ':headline',
        'subject' => 'Radar de croissance : une nouvelle action pour votre boutique',
        'cta'   => 'Ouvrir le Radar de croissance',
        'footer' => 'Vous recevez ce message car vous avez Black Pepper. Au plus un e-mail par semaine.',
        'result_title' => 'Les résultats de votre :kind sont là',
        'result_body'  => ':headline',
    ],

    'coupon' => [
        'title'     => ':discount sur :product, rien que pour vous',
        'body'      => 'Utilisez le code :code au paiement — une offre privée de :shop.',
        'subject'   => 'Une offre privée sur :product',
        'preheader' => ':discount, réservé pour vous.',
        'intro'     => ':shop a vu que :product vous plaisait. Voici un code que vous seul pouvez utiliser :',
        'expires'   => 'Valable jusqu\'au :date.',
    ],

    'errors' => [
        'audience_too_small' => 'Pas assez d\'acheteurs intéressés pour l\'instant (il en faut au moins :min pour que personne ne soit ciblé individuellement). Réessayez plus tard.',
        'audience_product'   => 'Un code ciblé doit inclure le produit de la carte.',
        'refresh_cooldown'   => 'Le Radar de croissance a été actualisé il y a quelques minutes. Réessayez dans :minutes min.',
    ],
];
