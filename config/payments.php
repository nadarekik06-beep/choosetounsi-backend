<?php

/*
|--------------------------------------------------------------------------
| Manual payments (WhatsApp)
|--------------------------------------------------------------------------
| Temporary way for sellers to pay ad-wallet top-ups and plan upgrades until
| Konnect / Flouci are integrated: the seller sends a pre-filled WhatsApp
| message, pays by D17 / bank transfer, and an admin approves the request.
|
| These are defaults only — admins override them in platform_settings under
| "payments.<key>" (App\Services\Payments\ManualPaymentSettings). The minimum
| top-up is the sponsoring setting ads.min_top_up (one value for every method).
|
| Template placeholders: {reference} {type} {amount} {store} {seller_name}
| {seller_id} {email} {phone} {balance} {date} {current_plan} {requested_plan}
| {price} {period}
*/

return [

    'defaults' => [
        'whatsapp_enabled' => true,
        'whatsapp_number'  => '+216 57 252 576',
        'max_top_up'       => 5000.000,

        'template_wallet_topup' => "Bonjour, je souhaite recharger mon portefeuille publicitaire.\n"
            . "Référence : {reference}\n"
            . "Type : {type}\n"
            . "Montant : {amount} DT\n"
            . "Boutique : {store} | Vendeur : {seller_name} | ID : {seller_id}\n"
            . "Email : {email} | Tél : {phone}\n"
            . "Solde actuel : {balance} DT\n"
            . "Date : {date}",

        'template_plan_upgrade' => "Bonjour, je souhaite changer d'abonnement.\n"
            . "Référence : {reference}\n"
            . "Type : {type}\n"
            . "Plan actuel : {current_plan} → Plan demandé : {requested_plan}\n"
            . "Prix : {price} DT ({period})\n"
            . "Boutique : {store} | Vendeur : {seller_name} | ID : {seller_id}\n"
            . "Email : {email} | Tél : {phone}\n"
            . "Solde publicitaire : {balance} DT\n"
            . "Date : {date}",
    ],

    // A seller can't pile up more than this many pending top-up requests.
    'max_pending_top_ups' => 3,
];
