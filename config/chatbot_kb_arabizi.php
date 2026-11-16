<?php

/**
 * Arabizi (Tunisian in Latin letters) texts of the knowledge base, for the sections that have no
 * Latin-script Tunisian text in chatbot_kb.php. Same sections and same number of steps as there;
 * {placeholders} are filled from PlatformFacts (French number formats: "5 DT").
 * Sections missing here fall back to the Arabic-script text. Edit freely: no code change needed.
 *
 *   topic => section => ['intro' => ..., 'steps' => [[title, text], ...]]
 */
return [

    'become_vendor' => [
        'apply' => [
            'intro' => "7el boutique fi Choose'Tounsi mahouch b flous (gratuit). Hedhi el khotowat:",
            'steps' => [
                ['Od5el l compte mte3ek', "Lazem 3andek compte Choose'Tounsi. Od5el, w ba3d efta7 page « Become a Seller »."],
                ['3ammer el talab', 'El formulaire fih 3 marahel: esmek el kamel, num el telifoun w esm el boutique, el ville, taswira mte3ek w taswira wa7da 3al a9al l produit.'],
                ['Stanna el mouwafa9a', "Fari9na yrajja3 el talabet fi 2 l 3 ayem khedma. Kan famma 7aja lazem tetsala7, tnajem tbaddelha w tab3ath el talab men jdid."],
                ['Zid produitek', "Ba3d ma yetkabel el talab, mchi l dashboard mte3 el bayee3 → Products → « Add Product ». Kol produit jdid yet9ayyes men admin 9bal ma yetna9ach fil site."],
                ['Rajjaa9 el plan kan t7eb', 'Tebda b Green Pepper (gratuit, {green_products}). Tnajem tetraqqa l Red wala Black Pepper ba3d men Seller → Subscription.'],
            ],
        ],
        'plans' => [
            'intro' => 'Famma 3 plans lel bayi3in:',
            'steps' => [
                ['Green Pepper — gratuit', '{green_products_cap}. Commission {green_commission} 3la kol bay3a.'],
                ['Red Pepper — {red_price}/chhar', '{red_products_cap}. Commission {red_commission} 3la kol bay3a.'],
                ['Black Pepper — {black_price}/chhar', '{black_products_cap}. Commission {black_commission} 3la kol bay3a.'],
                ['Kifech tbaddel el plan', "Kol wa7ed yebda b Green Pepper. Ba3d ma yetkabel el talab mte3ek, traqqa men Seller → Subscription. El liste el kamla mte3 kol plan mawjouda fi page « Become a Seller »."],
            ],
        ],
        'commission' => [
            'intro' => "Choose'Tounsi ta5ou commission 3la kol bay3a. Tkhtalef 7asb el soum mte3 el produit w el plan mte3ek:",
            'steps' => [
                ['Taux el asesi 7asb el soum', '{tier_list}'],
                ['Tkhfid 7asb el plan', 'Red Pepper yen9ess {red_reduction} nokat w Black Pepper {black_reduction} nokat. El taux ma yetna9ach ta7t {min_rate}%.'],
                ['9adech tkhalles', 'Green Pepper {green_commission}, Red Pepper {red_commission}, Black Pepper {black_commission}.'],
                ['Ma3a coupon', "Kan t3ammel coupon, el taux yetkhtar men el soum el asli ama yetsa7ab 3al soum ba3d el tkhfid mte3ek."],
            ],
        ],
        'add_products' => [
            'intro' => 'Kifech tzid produits ba3d ma yetkabel compte el bayee3:',
            'steps' => [
                ['Efta7 produitek', 'Mchi l dashboard mte3 el bayee3 → Products w dous 3al « Add Product ».'],
                ['3ammer el tafasil', "El esm, el description, el 9ism, el soum, el stock w el tsawer. Zid variantes (ex. pointure wala couleur) kan el produit 3andou."],
                ['Stanna el mouraj3a', 'El produits el jdod yodhhrou « Pending » lin admin yousadi9 3lihom.'],
                ['7oudoud el plan', 'Green Pepper: {green_products}, Red Pepper: {red_products}, Black Pepper: {black_products}.'],
            ],
        ],
    ],

    'place_order' => [
        'overview' => [
            'intro' => "Kifech ta3mel commande fi Choose'Tounsi, b sohoula:",
            'steps' => [
                ['Lawej 3la produit', 'Esta3mel el recherche, chouf el a9sem, wala es2elni (ex. « sbabet a9al men 100 DT »).'],
                ['O5tar el options', "Fi page el produit, o5tar el pointure wala el couleur kan famma variantes, w el kamiya."],
                ['Zid lel panier', 'Dous 3al « Add to Cart » (wala « Buy Now » bech tcommandi el produit hedha wa7dou). Efta7 el panier men icône el panier.'],
                ['Kammel el commande', 'Fil panier dous 3al « Proceed to Checkout ». Lazem tkoun dakhel l compte mte3ek.'],
                ['El 3enwen w el coupon', "Okteb 3enwen el livraison w num el telifoun. 3andek coupon men bayee3? Okotbou fi khanet el coupon mte3 el bayee3 hedhaka (coupon wa7ed lel bayee3)."],
                ['Khalles w akked', "El khlas ykoun cash 3and el istilem: tkhalles wa9t twasselek el commande. Toro9 khlas o5ra bech yetwa99rou 9rib."],
            ],
        ],
        'coupon' => [
            'intro' => 'Kifech yekhdmou el coupons mte3 el bayi3in:',
            'steps' => [
                ['5odh code', "El coupons ya3mlouhom el bayi3in — 3adatan tel9ahom fi boutique el bayee3."],
                ['Esta3mlou fil checkout', "Fil checkout, kol bayee3 3andou khana lel coupon. Okteb el code w dous 3al tatbi9; el tkhfid yodhher 9bal ma takked."],
                ['El 9awa3ed', 'Coupon wa7ed lel bayee3. El coupon yekhdem kan 3la produits el bayee3 hedhaka, w ma yekhdemch 3al packs.'],
            ],
        ],
    ],

    'track_order' => [
        'statuses' => [
            'intro' => 'Hedha ma3na kol 7ala mte3 el commande:',
            'steps' => [
                ['Pending (fil intidhar)', 'El commande wsolet w tstanna el ta2kid.'],
                ['Processing / Confirmed (mou2akkda)', 'El commande tkabelet w yetjahhez fiha.'],
                ['Out for Delivery (fil tari9)', 'El commande fil tari9 lik.'],
                ['Delivered / Completed (wselet)', 'El commande wselet. Kan famma mochkel, 3andek {complaint_hours} sa3a ba3d el livraison bech ta3mel reclamation.'],
                ['Cancelled / Refunded (molgha / mourtaj3a)', 'El commande tlghat, wala el flous mte3ha irtajja3ou.'],
            ],
        ],
    ],

    'returns_complaints' => [
        'file' => [
            'intro' => 'Famma mochkel fil commande? Hedhi kifech ta3mel reclamation wala tordd el flous:',
            'steps' => [
                ['Thabbet fil wa9t', "3andek {complaint_hours} sa3a ba3d ma yousel el colis (date el livraison el 7a9i9iya). Nafs el article ma yetraja3ch zouz marrat."],
                ['Efta7 el formulaire', 'Mchi l Complaints → nouvelle reclamation w o5tar el commande w el articles el ma3niya.'],
                ['O5tar el sabab w zid tsawer', "O5tar el sabab: el pointure ma tmchich, 3atbane wala mkasser, article ghalet wala machi kima wasfou, wala ghayyart ra2yi. Zid 2 l 3 tsawer lel article (lazem fi kol sabab) w okteb el mochkel fi 20 7arf 3al a9al."],
                ['El awwel el tabdil, ba3dou el istirjaa3', "El tabdil yetsa3ref dima el awwel. El istirjaa3 yetsa3ref kan el sabab yesma7 bih: dima lel article el 3atbane, el mkasser wala el ghalet; lel pointure kan el tabdil mouch momken (stock fini); wala « ghayyart ra2yi » kan el article jdid, ma tlbesch w 3andou el etiquettes."],
                ['El masarif w el articles el mosta5nin', "El article el 3atbane, el mkasser wala el ghalet ma ykalfek chay. Lel pointure wala ghayyart ra2yi, livraison el tabdil 3alik w tkhalles lel livreur. Lel istirjaa3 ba3d « ghayyart ra2yi », masarif el retour yetn9asou w el montant el dhabt yodhher 9bal ma tab3ath el talab. « Ghayyart ra2yi » ma yosel7ech lel sous-vetements w lingerie, maillots, cosmetique, nadhafa 5assa, makla w el articles el mkhasssa."],
                ['Tabba3 el talab', "Tabba3ou fi « Mes retours ». El boutique tchouf tsawrek w fari9na yousadi9 (kan el boutique tarfodh, tnajem tolleb men fari9na ye7kem). Livreur ya5ou el article w yraj3elek flousek cash fil blasa: el montant yodhher 9bal w yetsabbet 9bal el enlevement. El boutique tfa77es el article ba3dha; kan ma yetabe9ch el wasf mte3ek, fari9na ye7kem 7ala b 7ala."],
            ],
        ],
    ],

    'account_help' => [
        'signup' => [
            'intro' => 'Kifech ta3mel compte:',
            'steps' => [
                ['Sajjel', 'Efta7 page Register, 3ammer el ma3loumet w dous 3al « Create Account ».'],
                ['Akked el email', 'Nab3thoulek lien ta2kid fil email. Efta7ou bech yetfa3el el compte mte3ek.'],
                ['Wala esta3mel Google', 'Dous 3al « Continue with Google » fi page el login wala el register bech todkhel b compte Google mte3ek.'],
            ],
        ],
        'password' => [
            'intro' => 'Nsit el mot de passe?',
            'steps' => [
                ['Efta7 « Forgot Password? »', 'Fi page el login, dous 3al « Forgot Password? ».'],
                ['5odh el lien', 'Okteb el email mte3ek w dous 3al « Send Reset Link ».'],
                ['O5tar mot de passe jdid', 'Efta7 el lien eli wsel fil email w o5tar el mot de passe el jdid.'],
                ['Sajjelt b Google?', 'Esta3mel kan « Continue with Google » bech todkhel.'],
            ],
        ],
        'wallet' => [
            'intro' => "7aja 3la el wallet mte3 Choose'Tounsi:",
            'steps' => [
                ['El khlas bih', 'El khlas bel wallet 9rib yetwa99er. Tawa el commandet yetkhallsou cash 3and el istilem.'],
                ['Zyedet el flous', "El rasid yzidou fari9na — kallemna men page el contact (https://choosetounsi.tn/contact) wala 3al WhatsApp +216 57 252 576."],
            ],
        ],
        'profile' => [
            'intro' => 'Idarat el compte mte3ek:',
            'steps' => [
                ['El profil', 'Chouf ma3loumet el compte fi page el profil. Bech tbaddel mot de passe nsitou, esta3mel « Forgot Password? » fi page el login.'],
                ['El 3enawin', 'Sajjel 3enawin el livraison mte3ek fi Account → Addresses.'],
            ],
        ],
    ],
];
