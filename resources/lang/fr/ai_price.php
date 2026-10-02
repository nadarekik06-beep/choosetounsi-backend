<?php

// Optimiseur de prix — textes du calcul de secours (quand Groq ne répond pas).
return [
    'strategy_entry'   => 'Prix d’entrée compétitif',
    'strategy_market'  => 'Prix aligné sur le marché',
    'reason_market'    => 'Basé sur :count prix réels du marché tunisien relevés sur :platforms (moyenne : :avg DT)',
    'reason_platform'  => 'Basé sur :count annonces ChooseTounsi de cette catégorie (moyenne : :avg DT)',
    'reason_none'      => 'Aucune donnée de marché disponible — recommandation fondée sur le prix actuel pour la catégorie :category',
    'reason_current'   => 'Votre prix actuel de :price DT est :positioning.',
    'positioning'      => ['underpriced' => 'en dessous du marché', 'competitive' => 'compétitif', 'overpriced' => 'au-dessus du marché', 'unknown' => 'difficile à situer'],
    'impact_entry'     => 'Un prix d’entrée compétitif devrait attirer les premiers acheteurs sur ChooseTounsi.',
    'impact_market'    => 'S’aligner sur le marché préserve la conversion tout en optimisant le chiffre d’affaires.',
    'competitors_market'   => ':platforms : :count annonces entre :min et :max DT.',
    'competitors_platform' => 'ChooseTounsi compte :count concurrents (moyenne :avg DT).',
    'competitors_none'     => 'Aucune donnée concurrente trouvée.',
    'overpriced'       => 'Votre prix est :pct % au-dessus de la moyenne du marché — envisagez de le baisser pour améliorer la conversion.',
    'room_to_increase' => 'Votre prix est :pct % en dessous du marché — vous avez peut-être de la marge pour l’augmenter.',
    'no_sales'         => 'Pas encore de vente — vérifiez que l’annonce a des photos complètes.',
    'psycho'           => 'Affichez :psycho DT au lieu de :price DT — un prix psychologique convertit mieux.',
];
