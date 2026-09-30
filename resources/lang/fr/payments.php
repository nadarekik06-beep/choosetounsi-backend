<?php

// Paiements manuels (WhatsApp) : recharges du portefeuille publicitaire et changements de plan.
return [
    'errors' => [
        'method_disabled'        => 'Le paiement via WhatsApp est désactivé pour le moment.',
        'not_seller'             => 'Seul un vendeur approuvé peut faire cette demande.',
        'amount_out_of_range'    => 'Le montant doit être compris entre :min et :max.',
        'invalid_amount'         => 'Montant invalide.',
        'too_many_pending'       => 'Vous avez déjà :count demande(s) de recharge en attente. Attendez leur traitement ou annulez-en une.',
        'upgrade_pending'        => 'Vous avez déjà une demande de changement de plan en attente (:reference). Annulez-la pour en créer une autre.',
        'plan_unavailable'       => 'Ce plan n\'est pas disponible.',
        'no_yearly_price'        => 'Le plan :plan n\'a pas de tarif annuel.',
        'not_an_upgrade'         => 'Le plan :plan n\'est pas supérieur à votre plan actuel.',
        'subscription_suspended' => 'Votre abonnement est suspendu. Contactez le support.',
        'already_decided'        => 'Cette demande a déjà été traitée (:status).',
        'not_found'              => 'Demande introuvable.',
        'use_payment_request'    => 'Le paiement par carte n\'est pas disponible. Faites une demande de changement de plan (paiement via WhatsApp).',
    ],
    'status' => [
        'pending'   => 'en attente',
        'approved'  => 'approuvée',
        'rejected'  => 'refusée',
        'cancelled' => 'annulée',
    ],
    'notif' => [
        'wallet_topup' => [
            'approved' => ['title' => 'Recharge :reference confirmée', 'body' => ':amount DT ont été ajoutés à votre portefeuille publicitaire. Vos campagnes en pause faute de solde reprennent automatiquement.'],
            'rejected' => ['title' => 'Recharge :reference refusée', 'body' => 'Votre demande de recharge a été refusée. Motif : :reason'],
        ],
        'plan_upgrade' => [
            'approved' => ['title' => 'Votre plan :plan est actif', 'body' => 'Paiement :reference reçu (:amount DT). Votre plan :plan est actif jusqu\'au :end.'],
            'rejected' => ['title' => 'Demande :reference refusée', 'body' => 'Votre demande de passage au plan :plan a été refusée. Motif : :reason'],
        ],
        'view' => 'Voir le détail',
    ],
];
