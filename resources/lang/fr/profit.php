<?php

// Centre de profit (Black Pepper): goal API messages, alerts, CSV export.
return [
    'goal' => [
        'saved'           => 'Objectif enregistré.',
        'deleted'         => 'Objectif supprimé.',
        'month_invalid'   => 'Vous pouvez fixer un objectif pour ce mois-ci ou le mois prochain uniquement.',
        'net_above_sales' => 'Les gains nets visés ne peuvent pas dépasser l’objectif de ventes.',
    ],
    'alerts' => [
        'saved' => 'Préférences d’alerte enregistrées.',
    ],
    'notify' => [
        'cta'    => 'Voir mon Centre de profit',
        'footer' => 'Vous recevez cet e-mail car vous avez activé les alertes d’objectif dans votre Centre de profit. Désactivez-les à tout moment depuis la cloche de votre objectif.',

        'milestone_title'      => ':pct % de votre objectif atteint',
        'milestone_body'       => ':sales sur :goal en :month. Encore :remaining en :days jours.',
        'milestone_done_title' => 'Objectif de :month atteint !',
        'milestone_done_body'  => 'Bravo : :sales de ventes pour un objectif de :goal.',

        'pace_title' => 'Votre rythme est en dessous de votre objectif',
        'pace_body'  => 'Projection : :projection pour un objectif de :goal. Il faut :required par jour pendant :days jours (actuellement :current par jour).',

        'weekly_title'   => 'Votre semaine en bref',
        'weekly_body'    => '7 derniers jours : :week_sales (:orders commandes).',
        'weekly_goal'    => 'Objectif de :month : :pct % atteint, projection :projection.',
        'weekly_no_goal' => 'Fixez un objectif pour suivre votre rythme.',

        'recap_title'    => 'Bilan de :month',
        'recap_hit'      => '{1} Objectif atteint : :sales pour :goal (:pct %). Gains nets : :net. Première série lancée !|[2,*] Objectif atteint : :sales pour :goal (:pct %). Gains nets : :net. Série : :streak mois d’affilée.|[0] Objectif atteint : :sales pour :goal (:pct %). Gains nets : :net.',
        'recap_missed'   => ':sales sur :goal (:pct %). Gains nets : :net. Un nouveau mois commence : ajustez votre objectif.',
        'recap_no_goal'  => 'Ventes : :sales, gains nets : :net.',

        'new_goal_title'     => 'Nouveau mois, nouvel objectif',
        'new_goal_body'      => 'Fixez votre objectif de :month pour suivre votre rythme jour après jour.',
        'new_goal_suggested' => 'Fixez votre objectif de :month. Suggestion d’après vos derniers mois : :suggested.',
    ],
    'export' => [
        'title'      => 'Centre de profit — rapport mensuel',
        'gross'      => 'Ventes brutes',
        'refunds'    => 'Retours remboursés',
        'commission' => 'Commission Choose’Tounsi',
        'shipping'   => 'Livraison à votre charge',
        'ads'        => 'Publicité (portefeuille)',
        'ads_credit' => 'Publicité (crédit offert, non déduit)',
        'net'        => 'Gains nets',
        'sales'      => 'Ventes confirmées',
        'delivered'  => 'Dont livrées',
        'orders'     => 'Commandes confirmées',
        'goal'       => 'Objectif',
        'achieved'   => 'Atteint',
        'col' => [
            'order' => 'Commande', 'date' => 'Date', 'status' => 'Statut', 'amount' => 'Montant (DT)',
            'commission' => 'Commission (DT)', 'shipping' => 'Livraison (DT)', 'net' => 'Net (DT)', 'payout' => 'Versement',
        ],
        'status' => [
            'pending' => 'En attente', 'confirmed' => 'Confirmée', 'completed' => 'Prête', 'out_for_delivery' => 'En livraison',
            'delivered' => 'Livrée', 'cancelled' => 'Annulée', 'refunded' => 'Remboursée',
        ],
    ],
];
