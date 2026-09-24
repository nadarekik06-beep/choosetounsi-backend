<?php

namespace App\Services\Chat;

/**
 * Multilingual concept lexicon for the chatbot's rule-based fast path.
 *
 * Maps English, French, Arabic and Tunisian darija (Arabic script and
 * Latin/arabizi with digits: 3=ع 5=خ 7=ح 9=ق) onto shared concept tags,
 * so intent rules are written once ("ORDER + WHERE → track_order")
 * instead of once per language.
 *
 * Also provides product synonyms (sabbat → chaussure/shoe/حذاء) so a
 * darija search matches French/English/Arabic product names in SQL.
 *
 * All patterns run on IntentExtractor::normalize()d text (lowercase).
 */
class DarijaLexicon
{
    /**
     * concept => regex alternation (no delimiters). Word boundaries are added
     * for Latin words; Arabic words are matched as substrings.
     */
    private const CONCEPTS = [
        'SELL'        => 'sell|selling|seller|vendor|vendre|vends|vendeur|vendeuse|nbi3|nbii|nbi3ou|nbiaa|نبيع|نبيعو|بيع|بائع|تاجر|nwali\s+vendeur|nwalli',
        'STORE'       => 'store|shop|boutique|magasin|7anout|hanout|محل|حانوت|بوتيك|متجر',
        'OPEN'        => 'open|ouvrir|ouvre|create|créer|creer|start|lancer|n7el|nhel|n7ell|nhell|نحل|نحلّ|نفتح|افتح|فتح|نعمل\s+بوتيك',
        'PLAN'        => 'pepper|abonnement|abonnements|subscription|subscriptions|seller\s+plans?|plans?\s+vendeurs?|اشتراك|باقة|باقات|الباقات',
        'COMMISSION'  => 'commission|commissions|komision|pourcentage|percentage|fees|frais|عمولة|العمولة|نسبة',
        'ADD_PRODUCT' => 'add\s+(a\s+)?products?|ajouter\s+(un\s+|des\s+)?produits?|nzid\s+produit|n7ot\s+produit|أضيف\s+منتج|إضافة\s+منتج|نزيد\s+منتج|نحط\s+منتج',
        'REQUIRE'     => 'requirements?|conditions?|documents?|papiers?|what\s+do\s+i\s+need|il\s+faut\s+quoi|chnowa\s+lazem|chnia\s+lazem|شروط|وثائق|شنوة\s+لازم|شنية\s+لازم',
        'ORDER'       => 'order|orders|commande|commandes|commander|kommand|komand|كوموند|كومند|كموند|طلب|طلبية|طلبيتي|طلباتي|nkomandi|ncommandi',
        'BUY'         => 'buy|purchase|acheter|achat|nechri|nchri|n4ri|نشري|شري|شراء',
        'HOW'         => 'how|comment|kifech|kifeh|kifach|كيفاش|كيف|كيفية|chnowa\s+na3mel|شنوة\s+نعمل|steps|étapes|etapes|marche|procédure',
        'PAY'         => 'pay|paying|payment|payer|paiement|payé|khalles|nkhalles|نخلص|نخلّص|خلاص|الدفع|دفع|d17|stripe|carte\s+bancaire|bank\s+card|credit\s+card|cash\s+on\s+delivery|cod|à\s+la\s+livraison|كاش',
        'COUPON'      => 'coupon|coupons|code\s+promo|promo\s+code|discount\s+code|code\s+de\s+réduction|كوبون|كود\s+تخفيض|كود',
        'CART'        => 'cart|basket|panier|lpanier|سلة|السلة|الباني',
        'VARIANT'     => 'size|sizes|taille|pointure|color|colour|couleur|variant|variante|مقاس|قياس|لون|taille\s+mte3',
        'WHERE'       => 'where|où|ou\s+est|win|wein|وين|فين|أين|وينو|وينها',
        'TRACK'       => 'track|tracking|suivi|suivre|status|statut|état|etat|تتبع|حالة|wsolt|wselt|w9te\s+tousel|وقتاش\s+توصل|وصلت|ma\s+wseltch|pas\s+reçu|pas\s+recu|not\s+received|late|retard|en\s+retard|تأخر|m3atla|معطلة',
        'MY_ORDERS'   => 'my\s+orders?|mes\s+commandes?|ma\s+commande|commande\s+mte3i|el\s+commande\s+mte3i|طلباتي|طلبيتي|الكوموند\s+متاعي|كوموند\s+متاعي|lcommande\s+mte3i',
        'DELIVERY'    => 'delivery|deliver|livraison|livreur|ليفريزون|توصيل|colis|kolis|كوليس|shipping|expédition',
        'RETURN'      => 'return|returns|retour|retourner|refund|refunds|rembourse|rembourser|remboursé|remboursée|remboursement|complaint|complain|réclamation|reclamation|plainte|exchange|échange|echange|damaged|endommagé|cassé|casse|broken|wrong\s+(item|size|color|product)|mauvais|nraja3|nrajja3|nbadel|نرجع|نرجّع|ترجيع|استرجاع|إرجاع|ارجاع|شكوى|شكاية|مكسور|mkasser|nbadlou|نبدل|تبديل',
        'ACCOUNT'     => 'account|compte|profil|profile|حساب|كونت|kont|compte\s+mte3i',
        'SIGNUP'      => 'sign\s*up|register|registration|inscription|inscrire|créer\s+(un\s+)?compte|creer\s+(un\s+)?compte|n3mel\s+compte|na3mel\s+compte|nhel\s+compte|n7el\s+compte|نعمل\s+كونت|نحل\s+كونت|تسجيل|نسجل|سجل',
        'LOGIN'       => 'log\s*in|login|sign\s*in|connexion|connecter|connecte|ندخل|دخول|nod5el|nodkhol|nodkhel',
        'GOOGLE'      => 'google|gmail|قوقل|غوغل|جوجل',
        'PASSWORD'    => 'password|mot\s+de\s+passe|mdp|motdepasse|oublié|oublie|forgot|forgotten|nsit|نسيت|كلمة\s+السر|كلمة\s+العبور|كلمة\s+المرور|mot\s+passe',
        'WALLET'      => 'wallet|portefeuille|solde|balance|محفظة|المحفظة|رصيد|flousi|فلوسي',
        'CATEGORIES'  => 'categories|category|catégories|catégorie|rayons|sections|أقسام|الأقسام|قسم|des\s+rayons|chnowa\s+3andkom|شنوة\s+عندكم|what\s+do\s+you\s+sell|vous\s+vendez\s+quoi|find\s+(me\s+)?a\s+product|trouver\s+un\s+produit|نلقى\s+منتج|nal9a\s+produit',
        'MEANING'     => 'mean|meaning|means|signifie|signifient|signification|veut\s+dire|يعني|معنى|معناها|ya3ni|chnowa\s+ma3neha|ma3na',
        'CHEAP'       => 'cheap|cheaper|cheapest|pas\s+cher|moins\s+cher|abordable|rkhis|rkhes|ar5es|r5is|رخيص|أرخص|ارخص',
        'EXPENSIVE'   => 'expensive|pricier|cher|chère|ghali|8ali|ghalia|غالي|غالية|أغلى|اغلى',
        'HOW_MUCH'    => 'how\s+much|combien|quel\s+prix|9adech|9adeh|qadech|bkadech|bgaddech|b9adech|قداش|بقداش|بكم|قديش',
    ];

    /**
     * Darija word => product synonyms added to SQL keywords.
     * Keys are matched as whole tokens.
     */
    private const PRODUCT_SYNONYMS = [
        'sabbat'   => ['sabbat', 'chaussure', 'shoe', 'حذاء'],
        'sbabet'   => ['chaussure', 'shoe', 'حذاء'],
        'صباط'     => ['صباط', 'حذاء', 'chaussure', 'shoe'],
        'صبابط'    => ['حذاء', 'chaussure', 'shoe'],
        'sbardila' => ['basket', 'sneaker', 'حذاء رياضي'],
        'sbadri'   => ['basket', 'sneaker', 'حذاء رياضي'],
        'سبرديلة'  => ['basket', 'sneaker', 'حذاء رياضي'],
        '7wayej'   => ['vêtement', 'clothes', 'ملابس'],
        'hwayej'   => ['vêtement', 'clothes', 'ملابس'],
        'حوايج'    => ['ملابس', 'vêtement', 'clothes'],
        'ta9chira' => ['t-shirt', 'tshirt', 'تيشرت'],
        'tak9chira'=> ['t-shirt', 'tshirt', 'تيشرت'],
        'تقشيرة'   => ['t-shirt', 'تيشرت', 'tshirt'],
        'maryoul'  => ['t-shirt', 'maillot', 'تيشرت'],
        'مريول'    => ['t-shirt', 'تيشرت'],
        'sac'      => ['sac', 'bag', 'حقيبة'],
        'shanta'   => ['sac', 'bag', 'حقيبة'],
        'chanta'   => ['sac', 'bag', 'حقيبة'],
        'شنطة'     => ['حقيبة', 'sac', 'bag'],
        'serwel'   => ['pantalon', 'trousers', 'سروال'],
        'sirwel'   => ['pantalon', 'trousers', 'سروال'],
        'سروال'    => ['سروال', 'pantalon', 'trousers'],
        'mongela'  => ['montre', 'watch', 'ساعة'],
        'منڨالة'   => ['ساعة', 'montre', 'watch'],
        'منقالة'   => ['ساعة', 'montre', 'watch'],
        '3asal'    => ['miel', 'honey', 'عسل'],
        'asal'     => ['miel', 'honey', 'عسل'],
        'zit'      => ['huile', 'oil', 'زيت'],
        'zitouna'  => ['huile d\'olive', 'olive oil', 'زيت زيتون'],
        'kas'      => ['verre', 'glass', 'كاس'],
        'telifoun' => ['téléphone', 'phone', 'هاتف'],
        'portable' => ['téléphone', 'phone', 'هاتف'],
        'تليفون'   => ['هاتف', 'téléphone', 'phone'],
        'jebba'    => ['jebba', 'جبة'],
        'جبة'      => ['جبة', 'jebba'],
        'fota'     => ['fouta', 'serviette', 'فوطة'],
        'fouta'    => ['fouta', 'serviette', 'فوطة'],
        'rou7ya'   => ['parfum', 'perfume', 'عطر'],
        'ri7a'     => ['parfum', 'perfume', 'عطر'],
        'ريحة'     => ['عطر', 'parfum', 'perfume'],
    ];

    /** Latin-script darija words that mark a message as Tunisian (reply in Arabic). */
    public const DARIJA_LATIN = [
        'n7eb', 'nheb', 'nhib', 'n7ib', 'nlawej', 'nlawjou', '3andkom', '3andek', '3andi', 'andkom', 'fama', 'famma',
        'chneya', 'chnoua', 'chnowa', 'chnia', 'bech', 'besh', 'barcha', 'bahi', 'behi', 'warini', 'wariny', 'choufli',
        'mte3', 'mta3', 'mte3i', 'zeda', 'chwaya', 'chwia', '9adeh', '9adech', 'bkadech', 'b9adech', 'ya5i', 'brabi',
        'aslema', 'sabbat', 'sbabet', 'sbadri', 'sbardila', 'mrigel', 'mrigla', 'yesser', 'rkhis', 'rkhes', 'ar5es',
        'r5is', 'a9al', 'mabin', 'kifech', 'kifeh', 'kifach', 'chkoun', 'bechhal', 'bgaddech', '3aychek', 'nchri',
        'nechri', 'lbesa', 'hwayej', '7wayej', 'ta9chira', 'sa7a', 'labes', 'nbi3', 'nbii', 'n7el', 'nhell', 'win',
        'wein', 'wsolt', 'wselt', 'nraja3', 'nrajja3', 'nbadel', 'nsit', 'ghali', '8ali', 'ghalia', 'khalles',
        'nkhalles', 'komand', 'kommand', 'lcommande', 'nkomandi', 'ncommandi', 'kolis', 'shanta', 'chanta', 'serwel',
        'mongela', 'na3mel', 'n3mel', 'nod5el', 'nodkhol', 'nwali', 'nwalli', 'lpanier', '7anout', 'hanout',
        'mkasser', 'tawa', 'kifma', 'w9tech', 'wseltch', 'wsoltch', 'jani', 'jéni', 'ma3andich', 'mafamech', 'waqtech', 'ma3neha', 'ya3ni',
    ];

    /**
     * @return string[] concept tags present in the text, e.g. ['ORDER', 'WHERE']
     */
    public function concepts(string $text): array
    {
        $found = [];
        foreach (self::CONCEPTS as $concept => $alternation) {
            if (preg_match($this->regex($alternation), $text)) {
                $found[] = $concept;
            }
        }
        return $found;
    }

    public function has(string $text, string $concept): bool
    {
        return isset(self::CONCEPTS[$concept]) && (bool) preg_match($this->regex(self::CONCEPTS[$concept]), $text);
    }

    /**
     * Expand darija product words into multilingual search keywords.
     *
     * @param string[] $tokens
     * @return string[]
     */
    public function expandKeywords(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $out[] = $token;
            foreach (self::PRODUCT_SYNONYMS[$token] ?? [] as $synonym) {
                $out[] = $synonym;
            }
        }
        return array_values(array_unique($out));
    }

    /** Every Latin/Arabic word used by the concept rules — treated as non-product words. */
    public function conceptWords(): array
    {
        static $words = null;
        if ($words === null) {
            $words = [];
            foreach (self::CONCEPTS as $concept => $alternation) {
                if (in_array($concept, ['CHEAP', 'EXPENSIVE', 'VARIANT'], true)) {
                    continue; // these can legitimately qualify a product search
                }
                foreach (explode('|', $alternation) as $alt) {
                    if (!preg_match('/[\\\\()?+*]/', $alt)) {
                        $words[] = $alt;
                    }
                }
            }
        }
        return $words;
    }

    private function regex(string $alternation): string
    {
        // Latin words need boundaries ("cod" must not match "code"); Arabic
        // words are glued to prefixes (ال، و، ب) so they match as substrings.
        return '/(?<![\p{Latin}\p{N}])(?:' . $alternation . ')(?![\p{Latin}\p{N}])/u';
    }
}
