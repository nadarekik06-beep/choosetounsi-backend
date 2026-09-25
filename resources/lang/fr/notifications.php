<?php

// Generated from one shared table (fr / ar / en) — keep the three files in sync.

return [
    'complaint_approved' => [
        'title' => 'Réclamation acceptée ✅',
        'body' => 'Votre réclamation a été acceptée. Nous vous contacterons pour la suite.',
        'message' => 'Votre réclamation a été acceptée. Consultez votre e-mail pour la suite.',
        'subject' => '✅ Votre réclamation a été acceptée — Commande :order',
        'line1' => 'Bonne nouvelle ! Votre réclamation pour la commande **:order** a été **acceptée**.',
        'line2' => 'Notre équipe vous contactera rapidement pour la suite (remboursement ou remplacement).',
        'action' => 'Voir mes réclamations',
    ],
    'complaint_rejected' => [
        'title' => 'Réclamation refusée ❌',
        'body' => 'Votre réclamation n\'a pas été acceptée. Motif : :reason',
        'subject' => '❌ Mise à jour de votre réclamation — Commande :order',
        'line1' => 'Nous avons examiné votre réclamation pour la commande **:order**.',
        'line2' => 'Malheureusement, après un examen attentif, votre réclamation n\'a pas pu être acceptée.',
        'reason' => '**Motif :** :reason',
        'line3' => 'Si vous pensez qu\'il s\'agit d\'une erreur, contactez notre service client.',
        'action' => 'Contacter le support',
    ],
    'refund_completed' => [
        'title' => '✅ Votre remboursement a été effectué',
        'message' => 'Le remboursement de la commande #:order est terminé. Le statut de votre commande est passé à Remboursée.',
        'subject' => '✅ Remboursement effectué — Commande #:order',
        'line1' => 'Nous avons le plaisir de vous informer que le remboursement de votre commande **#:order** a bien été effectué.',
        'line2' => 'Notre livreur a récupéré l\'article et le retour a été confirmé.',
        'line3' => 'Le statut de votre commande est passé à **Remboursée**.',
        'action' => 'Voir mes commandes',
        'thanks' => 'Merci d\'avoir choisi Choose\'Tounsi. Nous nous excusons pour la gêne occasionnée.',
    ],
    'review_prompt' => [
        'title' => 'Comment s\'est passée votre commande ?',
        'message' => 'Partagez votre avis sur :products',
        'product' => 'Produit',
    ],
    'mail' => [
        'greeting' => 'Bonjour :name,',
        'thanks' => 'Merci de faire vos achats sur ChooseTounsi.',
    ],
];
