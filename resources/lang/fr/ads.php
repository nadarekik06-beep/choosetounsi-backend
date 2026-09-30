<?php

return [
    'errors' => [
        'not_ready'             => 'Ce produit n\'est pas encore prêt à être boosté. Corrigez d\'abord les points bloquants.',
        'already_open'          => 'Ce produit a déjà une campagne. Modifiez-la, mettez-la en pause ou annulez-la.',
        'cpc_below_min'         => 'Le coût maximum par clic doit être d\'au moins :min DT pour cette catégorie.',
        'budget_below_min'      => 'Le budget quotidien doit être d\'au moins :min DT.',
        'budget_below_cpc'      => 'Le budget quotidien doit couvrir au moins un clic (:min DT).',
        'total_below_daily'     => 'Le budget total ne peut pas être inférieur au budget quotidien.',
        'wallet_too_low'        => 'Votre portefeuille publicitaire doit contenir au moins :required DT (disponible : :available DT). Rechargez-le d\'abord.',
        'invalid_amount'        => 'Montant invalide.',
        'adjust_below_zero'     => 'Cet ajustement rendrait le portefeuille négatif.',
        'invalid_end_date'      => 'La date de fin doit être dans le futur.',
        'invalid_price_range'   => 'Le prix cible maximum ne peut pas être inférieur au minimum.',
        'invalid_placements'    => 'Emplacement inconnu.',
        'not_open'              => 'Cette campagne est déjà terminée.',
        'not_active'            => 'Seules les campagnes en cours peuvent être mises en pause.',
        'not_paused'            => 'Cette campagne n\'est pas en pause.',
        'paused_by_admin'       => 'Cette campagne a été mise en pause par l\'équipe Choose\'Tounsi. Contactez le support pour la relancer.',
        'paused_by_plan'        => 'Cette campagne est en pause car votre abonnement n\'inclut plus le sponsoring. Elle reprendra si vous changez d\'abonnement.',
        'budget_exhausted_today' => 'Le budget du jour est épuisé. La campagne reprendra automatiquement demain.',
        'campaign_ended'        => 'La date de fin de cette campagne est dépassée.',
        'product_unavailable'   => 'Le produit est inactif ou en rupture de stock.',
        'top_up_below_min'      => 'La recharge minimum est de :min DT.',
        'gateway_unavailable'   => 'Ce moyen de paiement n\'est pas disponible.',
        'top_up_not_pending'    => 'Cette recharge a déjà été traitée.',
        'not_found'             => 'Campagne introuvable.',
        'product_not_found'     => 'Produit introuvable.',
    ],

    'notif' => [
        'activated' => [
            'title' => 'Campagne en ligne : :name',
            'body'  => 'Votre annonce pour « :name » est diffusée — :budget DT/jour, :cpc DT max par clic.',
        ],
        'paused' => [
            'title'            => 'Campagne en pause : :name',
            'wallet_empty'     => 'Votre portefeuille publicitaire est vide. Rechargez-le pour relancer la campagne.',
            'out_of_stock'     => '« :name » est en rupture de stock. La campagne reprendra après réapprovisionnement.',
            'product_inactive' => '« :name » n\'est plus actif, sa campagne est donc en pause.',
            'plan_downgrade'   => 'Votre abonnement n\'inclut plus le sponsoring. La campagne reprendra si vous changez d\'abonnement.',
            'admin'            => 'L\'équipe Choose\'Tounsi a mis cette campagne en pause. Consultez vos messages ou contactez le support.',
        ],
        'ended' => [
            'completed' => ['title' => 'Campagne terminée : :name', 'body' => 'Dépensé :spend DT · :clicks clics · :orders commandes · :revenue DT de ventes.'],
            'cancelled' => ['title' => 'Campagne annulée : :name', 'body' => 'Dépensé :spend DT · :clicks clics · :orders commandes · :revenue DT de ventes.'],
            'rejected'  => ['title' => 'Campagne refusée : :name', 'body' => 'Motif : :reason. :refund DT ont été remboursés sur votre portefeuille publicitaire.'],
        ],
        'budget_alert' => [
            'title' => '80 % du budget du jour dépensé : :name',
            'body'  => ':spent DT dépensés sur :budget DT aujourd’hui. La campagne se met en pause quand le budget est épuisé et reprend demain.',
        ],
        'wallet_low' => [
            'title' => 'Votre portefeuille publicitaire est presque vide',
            'body'  => 'Il reste :available DT — environ :days jour(s) de budget de vos campagnes. Rechargez-le pour qu’elles continuent.',
        ],
        'low_performance' => ['title' => 'Votre campagne a besoin d’attention : :name', 'low_ctr' => 'Peu d’acheteurs cliquent — essayez de meilleures photos, un titre plus clair ou un prix plus attractif.', 'low_roas' => 'Elle coûte plus qu’elle ne rapporte pour l’instant — baissez votre CPC max, améliorez la fiche ou ajoutez une remise.'],
        'view' => 'Voir la campagne',
        'roas' => 'Retour sur dépense publicitaire : :roas×',
    ],
];
