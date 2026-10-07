<?php

// Buyer notifications (bell + e-mail). App\Notifications\Buyer\*
// Users with another locale fall back to these French texts (app.fallback_locale).

return [

    'money' => ':amount DT',
    'and'   => 'et',

    'mail' => [
        'greeting'           => 'Bonjour :name,',
        'greeting_anonymous' => 'Bonjour,',
        'signature'          => 'À très vite,',
        'button_default'     => 'Voir le détail',
        'footer' => [
            'orders'     => 'Vous recevez cet e-mail car vous avez passé une commande sur ChooseTounsi. Gérez vos e-mails dans Profil → Paramètres → Notifications.',
            'payments'   => 'Vous recevez cet e-mail au sujet d\'un paiement sur ChooseTounsi. Gérez vos e-mails dans Profil → Paramètres → Notifications.',
            'complaints' => 'Vous recevez cet e-mail au sujet d\'une réclamation sur ChooseTounsi. Gérez vos e-mails dans Profil → Paramètres → Notifications.',
            'reviews'    => 'Vous pouvez désactiver les rappels d\'avis dans Profil → Paramètres → Notifications.',
            'promotions' => 'Vous recevez cet e-mail car vous avez accepté nos offres. Vous pouvez vous désabonner dans Profil → Paramètres → Notifications.',
            'account'    => 'Message de sécurité envoyé à tous les comptes concernés : il ne peut pas être désactivé.',
        ],
    ],

    // ── Orders & delivery ─────────────────────────────────────────────────
    'order' => [
        'placed' => [
            'title'        => 'Commande :ref enregistrée',
            'body'         => '{1} :count article · total :total. Nous vous préviendrons à chaque étape.|[2,*] :count articles · total :total. Nous vous préviendrons à chaque étape.',
            'subject'      => 'Votre commande :ref est enregistrée',
            'line_cod'     => 'Paiement à la livraison : préparez :total pour le livreur.',
            'line_paid'    => 'Paiement reçu : :total.',
            'line_shops'   => 'Vendu par : :shops. Chaque boutique expédie son colis séparément.',
            'line_address' => 'Livraison : :address',
            'items_total'  => 'Articles',
            'discount'     => 'Réduction',
            'shipping'     => 'Livraison',
            'shipping_free'=> 'Offerte',
            'total'        => 'Total',
            'button'       => 'Suivre ma commande',
        ],
        'confirmed' => [
            'title'   => 'Commande :ref confirmée',
            'body'    => 'Votre commande auprès de :shops est confirmée : la préparation commence.',
            'subject' => 'Commande :ref confirmée',
            'line'    => 'Vous recevrez un message dès que votre colis sera remis au livreur.',
        ],
        'packed' => [
            'title'   => 'Colis prêt chez :shops',
            'body'    => 'Votre colis (commande :ref) est emballé et attend le livreur.',
            'subject' => 'Votre colis de :shops est prêt',
            'line'    => 'Il sera bientôt remis au livreur.',
        ],
        'shipped' => [
            'title'        => 'Votre colis de :shops est en route',
            'body'         => 'Commande :ref : votre colis a été remis au livreur.',
            'body_courier' => 'Commande :ref : :courier vous livre bientôt.',
            'subject'      => 'Votre colis de :shops a été expédié',
            'line'         => 'Gardez votre téléphone à portée de main : le livreur vous appellera avant de passer.',
            'line_cod'     => 'Montant à régler à la livraison : :amount.',
        ],
        'delivered' => [
            'title'   => 'Colis livré',
            'body'    => 'Votre colis de la boutique :shops (commande :ref) a été livré.',
            'subject' => 'Votre colis de :shops a été livré',
            'line'    => 'Un problème avec un article ? Vous avez :hours h pour le signaler depuis « Mes commandes ».',
        ],
        'cancelled' => [
            'title'          => 'Commande :ref annulée',
            'body_seller'    => 'La boutique :shops a annulé votre commande.',
            'body_admin'     => 'Votre commande auprès de :shops a été annulée.',
            'body_payment'   => 'Le paiement n\'a pas été finalisé : votre commande a été annulée.',
            'subject'        => 'Commande :ref annulée',
            'line_paid'      => 'Vous avez déjà payé : notre équipe vous contacte pour le remboursement de :amount.',
            'line_unpaid'    => 'Aucun montant ne vous sera facturé.',
            'line_help'      => 'Une question ? Notre support est là pour vous aider.',
            'button'         => 'Voir ma commande',
        ],
    ],

    // ── Payments & refunds ────────────────────────────────────────────────
    'payment' => [
        'pending' => [
            'title'   => 'Paiement en attente — commande :ref',
            'body'    => 'Nous attendons votre paiement :method de :total pour préparer votre commande.',
            'subject' => 'Finalisez le paiement de votre commande :ref',
            'line'    => 'La préparation commence dès la réception du paiement.',
        ],
        'failed' => [
            'title'   => 'Paiement refusé — commande :ref',
            'body'    => 'Le paiement de :total n\'a pas abouti. Réessayez ou choisissez un autre moyen de paiement.',
            'subject' => 'Le paiement de votre commande :ref n\'a pas abouti',
            'line'    => 'Sans paiement, la commande sera annulée automatiquement après :minutes minutes.',
            'button'  => 'Voir ma commande',
        ],
        'methods' => [
            'card'   => 'par carte',
            'd17'    => 'D17',
            'wallet' => 'par portefeuille',
            'cod'    => 'à la livraison',
        ],
    ],

    'refund' => [
        'picked_up' => [
            'title'   => 'Article récupéré — remboursement en cours',
            'body'    => 'Le livreur a récupéré votre article (commande :ref). Le remboursement est en cours de traitement.',
            'subject' => 'Votre retour (commande :ref) a été récupéré',
        ],
        'completed' => [
            'title'   => 'Remboursement effectué',
            'body'    => 'Le remboursement de votre commande :ref est terminé.',
            'subject' => 'Remboursement effectué — commande :ref',
            'line'    => 'Votre retour a été confirmé. Merci pour votre patience, et désolés pour la gêne occasionnée.',
            'button'  => 'Voir ma commande',
        ],
        'exchange_picked_up' => [
            'title'   => 'Article récupéré — échange en cours',
            'body'    => 'Le livreur a récupéré votre article (commande :ref). Votre échange est en cours de traitement.',
            'subject' => 'Votre article (commande :ref) a été récupéré',
        ],
        'exchange_completed' => [
            'title'   => 'Échange effectué',
            'body'    => 'L\'échange de votre commande :ref est terminé.',
            'subject' => 'Échange effectué — commande :ref',
            'line'    => 'Merci pour votre patience, et désolés pour la gêne occasionnée.',
            'button'  => 'Voir ma commande',
        ],
    ],

    // ── Complaints & returns ──────────────────────────────────────────────
    'complaint' => [
        'received' => [
            'title'       => 'Réclamation reçue',
            'body'        => 'Votre réclamation pour la commande :ref a bien été transmise à :shop.',
            'body_refund' => 'Votre demande de retour et remboursement (commande :ref) a bien été transmise à :shop.',
            'subject'     => 'Nous avons reçu votre réclamation — commande :ref',
            'line'        => 'Nous vous tiendrons informé(e) à chaque étape.',
        ],
        'seller_replied' => [
            'title'   => ':shop a répondu à votre réclamation',
            'body'    => 'Commande :ref : la boutique examine votre demande.',
            'subject' => 'Votre réclamation (commande :ref) est en cours d\'examen',
            'line'    => 'Message de la boutique : « :note »',
        ],
        'escalated' => [
            'title'   => 'Réclamation en cours d\'arbitrage',
            'body'    => 'Commande :ref : la boutique conteste votre demande, notre équipe va trancher.',
            'subject' => 'Votre réclamation (commande :ref) est examinée par notre équipe',
            'line'    => 'Vous recevrez notre décision par notification et par e-mail.',
        ],
        'approved' => [
            'title'       => 'Réclamation acceptée',
            'body'        => 'Votre réclamation (commande :ref) a été acceptée.',
            'body_refund' => 'Retour accepté (commande :ref) : un livreur passera récupérer l\'article, puis vous serez remboursé(e).',
            'subject'     => 'Votre réclamation a été acceptée — commande :ref',
            'line'        => 'Notre équipe vous contacte pour la suite.',
        ],
        'rejected' => [
            'title'   => 'Réclamation refusée',
            'body'    => 'Votre réclamation (commande :ref) n\'a pas été acceptée. Motif : :reason',
            'subject' => 'Mise à jour de votre réclamation — commande :ref',
            'line'    => 'Si vous pensez qu\'il s\'agit d\'une erreur, contactez notre support.',
        ],
        'button' => 'Voir ma réclamation',
    ],

    // ── Reviews ───────────────────────────────────────────────────────────
    'review' => [
        'prompt' => [
            'title'   => 'Votre avis compte',
            'body'    => 'Comment trouvez-vous :products ? Notez votre achat en une minute.',
            'subject' => 'Que pensez-vous de :products ?',
            'line'    => 'Votre avis aide les autres acheteurs et la boutique :shop.',
            'button'  => 'Donner mon avis',
        ],
        'reply' => [
            'title'   => ':shop a répondu à votre avis',
            'body'    => 'Sur :product : « :excerpt »',
            'subject' => ':shop a répondu à votre avis',
            'button'  => 'Voir la réponse',
        ],
    ],

    // ── Promotions ────────────────────────────────────────────────────────
    'promo' => [
        'coupon_expiring' => [
            'title'   => 'Votre code :code expire bientôt',
            'body'    => ':discount sur :product chez :shop, valable jusqu\'au :date.',
            'subject' => 'Dernier rappel : votre code :code expire le :date',
            'button'  => 'En profiter',
        ],
        'price_drop' => [
            'title'   => 'Baisse de prix sur un favori',
            'body'    => ':product passe de :old à :new.',
            'subject' => 'Le prix de :product a baissé',
            'button'  => 'Voir le produit',
        ],
        'back_in_stock' => [
            'title'   => 'De retour en stock',
            'body'    => ':product est de nouveau disponible.',
            'subject' => ':product est de retour en stock',
            'button'  => 'Voir le produit',
        ],
        'off'     => '-:value %',
        'fixed'   => '-:value DT',
    ],

    // ── Account & security ────────────────────────────────────────────────
    'account' => [
        'password_changed' => [
            'title'   => 'Mot de passe modifié',
            'body'    => 'Le mot de passe de votre compte vient d\'être changé.',
            'subject' => 'Votre mot de passe ChooseTounsi a été modifié',
            'line'    => 'Si ce n\'est pas vous, réinitialisez votre mot de passe immédiatement et contactez notre support.',
            'button'  => 'Gérer mon compte',
        ],
        'email_changed' => [
            'title'   => 'Adresse e-mail modifiée',
            'body'    => 'Votre compte utilise désormais l\'adresse :email.',
            'subject' => 'L\'adresse e-mail de votre compte a été modifiée',
            'line'    => 'Si vous n\'êtes pas à l\'origine de ce changement, contactez immédiatement notre support.',
            'button'  => 'Gérer mon compte',
        ],
        'application_approved' => [
            'title'   => 'Candidature acceptée !',
            'body'    => 'Votre boutique « :name » est validée : vous pouvez publier vos produits.',
            'subject' => 'Votre boutique « :name » est validée',
            'button'  => 'Ouvrir mon espace vendeur',
        ],
        'application_rejected' => [
            'title'   => 'Candidature non retenue',
            'body'    => 'Votre candidature vendeur pour « :name » n\'a pas été retenue.',
            'reason'  => 'Votre candidature vendeur pour « :name » n\'a pas été retenue. Motif : :reason',
            'subject' => 'Votre candidature vendeur',
            'button'  => 'Voir ma candidature',
        ],
    ],
];
