<?php

/**
 * Tunisian Darija lexicon of the shopping chatbot.
 *
 * EDIT THIS FILE to teach the bot new words. No code change is needed:
 *   - add a word to the right list, keep it lower-case;
 *   - Arabic words are matched after light normalisation (hamza, ta marbuta,
 *     diacritics are ignored) and with the prefixes ال / ل / ب / و / ف;
 *   - Latin words are matched as whole words.
 *
 * Sections
 *   signals    words that mark a message as Tunisian (Latin "arabizi" / Arabic script) or MSA
 *   recipients "for whom" words  → recipient + gender
 *   gender     explicit gender words (regex fragments)
 *   concepts   product words → category / subcategories (shoes, dresses, ...)
 *   colors     color words → canonical color (+ synonyms used to match DB values)
 *   price      cheap / expensive / promo words
 *   templates  fixed texts per dialect (fr, en, tn_arabizi, tn_arabic, msa)
 *   fewshot    examples given to Groq to write natural replies
 */
return [

    // ── Language signals ─────────────────────────────────────────────────
    'signals' => [
        // Tunisian written in Latin letters (digits = letters: 3 ع, 7 ح, 9 ق, 5 خ, 8 غ, 2 ء)
        'arabizi' => [
            'nheb', 'n7eb', 'nhib', 'n7ib', 'nechri', 'nchri', 'n4ri', 'nlawej', 'nlawjou', 'nal9a', 'nal9aw', 'chnowa',
            'chnouwa', 'chnoua', 'chneya', 'chnia', 'chnahwalek', '9adeh', '9adech', '9addech', 'b9adech', 'bkadech',
            'bgaddech', 'qadech', 'barcha', 'barsha', 'behi', 'bahi', 'mte3', 'mta3', 'mte3i', 'mta3i', 'mte3ek', 'bech',
            'bch', 'besh', 'famma', 'fama', 'mafamech', 'ya3tik', 'ya3tek', 'sa7ebti', 'sahebti', 'sa7bi', 'sahbi',
            'sa7be', 'mrati', 'mra', 'rajli', 'rajel', 'rjel', 'rijel', 'bouya', 'khouya', 'okhti', 'omi', 'ommi',
            'rkhis', 'rkhes', 'rkhisa', 'ar5es', 'r5is', 'ghali', 'ghalia', '8ali', '7aja', 'haja', '7wayej', 'hwayej',
            'sabat', 'sabbat', 'sbat', 'sbabet', 'sbabat', 'sabbet', 'serwel', 'sirwel', 'mriguel', 'mrigel', 'taw',
            'tawa', 'chwaya', 'chwia', 'a9al', 'akther', 'akthar', 'a5ter', '3andek', '3andkom', '3andi', '3andna',
            'n7ebha', 'n7ebou', 'nheb', 'lel', 'el', 'bel', 'bil', 'mel', 'kifech', 'kifeh', 'kifach', 'nkhallas',
            'nkhalles', 'n5allas', 'nkhalas', 'khalles', 'ta7t', 'ta7et', 'fo9', 'fou9', 'a7mer', 'ak7el', 'abyedh',
            'azre9', 'akhdhar', 'akhdher', 'asfar', 'o5ra', 'o5rin', 'okhra', 'okhrin', 'zeda', 'yesser', 'brabi',
            'ya5i', 'winou', 'win', 'wein', 'mouch', 'moch', 'mech', 'ma3andich', 'jebba', 'kabbout', 'kabbut',
            'shanta', 'chanta', 'sak', 'nsa', 'nessa', 'bent', 'weld', 'wlid', 'sghar', 'kbar', 'mriguel', 'tbarkallah',
            'aslema', 'ahla', 'saha', 'sa7a', 'labes', 'n3mel', 'na3mel', 'nod5el', 'nsit', 'wsolt', 'wselt', 'kolis',
            'salem', 'salam', 'slm', 'salamou', 'alikom', 'alaykom', 'alaykoum', '3alikom', 'marhba', 'y3aychek', 'ya3aychek', '3aychek', 'bslema', 'beslama', 'bessalama',
            'choukran', 'chokran', 'ta7ki', 't7ki', 'tahki', 'tfhem', 'tefhem', 'ta3ref', 'chkoun', 'chkon', 'chkoon', '3mal', 'sna3',
            'moula', 'wara', 'hedhi', 'hedha', 'hedhom', 'hadha', 'hedhoukom', 'thabet', 'tawa', 'bidhabt', 'm3ak', 'ya3ni',
            'blech', 'blesh', 'blach', 'nbi3', 'nbii', 'nbi3ou', 'n7el', 'nhel', 'nhell', 'wsolt', 'wseltch', 'wsoltch', 'w9tech', 'waqtech', 'mafamech', 'nraja3', 'nrajja3',
            'nbadel', 'mkasser', 'kifma', 'jani', 'komand', 'kommand', 'lcommande', 'nkomandi', 'ncommandi', 'lpanier', '7anout', 'hanout',
            'mongela', 'sbardila', 'sbadri', 'mrigla', 'bechhal', '3aychek', 'chkoun', 'lbesa', 'ta9chira', 'nwali', 'nwalli', 'telifoun',
            'zit', 'zitouna', '3asal', 'ri7a', 'rou7ya', 'nod5el', 'nodkhol', 'nkhalles',
        ],
        // Tunisian written in Arabic script
        'arabic' => [
            'نحب', 'نشري', 'نلوج', 'شنوة', 'شنية', 'شنوا', 'قداش', 'قداه', 'بقداش', 'برشا', 'برشة', 'باهي', 'متاع', 'متاعي',
            'متاعك', 'باش', 'فما', 'فمة', 'ما فماش', 'صاحبتي', 'صاحبي', 'مرتي', 'راجلي', 'رخيص', 'رخيصة', 'غالي', 'غالية',
            'حاجة', 'حوايج', 'سباط', 'صباط', 'سبابط', 'صبابط', 'سروال', 'توة', 'توا', 'شوية', 'كيفاش', 'نخلص', 'نخلّص',
            'وريني', 'عندكم', 'يعطيك', 'يعطيك الصحة', 'بالزربة', 'ياسر', 'موش', 'ماهوش', 'خويا', 'بوية',
            'شكارة', 'شلاكة', 'نبي', 'نحوس', 'لاباس', 'عسلامة', 'برك', 'هاذي', 'هاذاكا', 'هاذوما', 'مالا',
        ],
        // Formal Arabic markers (used only when no Tunisian marker is present)
        'msa' => [
            'أريد', 'اريد', 'أرغب', 'ارغب', 'من فضلك', 'لو سمحت', 'هل يوجد', 'هل لديكم', 'كم سعر', 'ما هو', 'ما هي',
            'أبحث', 'ابحث', 'أود', 'اود', 'أحتاج', 'احتاج', 'لدي', 'لديكم', 'يوجد', 'حذاء', 'أحذية', 'فستان', 'حقيبة',
        ],
        'fr' => ['je', 'veux', 'voudrais', 'cherche', 'bonjour', 'bonsoir', 'salut', 'merci', 'entre', 'moins', 'cher',
            'prix', 'pour', 'avec', 'une', 'des', 'les', 'est', 'pas', 'dinars', 'avez', 'vous', 'montre', 'moi', 'quel',
            'quelle', 'quels', 'commande', 'livraison', 'jusqu', 'plus', 'et', 'de', 'du', 'la', 'le', 'rouge', 'noir',
            'blanc', 'chaussures', 'robe', 'sac', 'comment', 'où', 'vendre', 'vendeur', 'boutique', 'compte', 'mot',
            'passe', 'remboursement', 'retour', 'payer', 'paiement', 'réclamation', 'combien', 'mes', 'mon', 'ma',
            'suivre', 'oublié', 'panier', 'catégories', 'devenir', 'faire', 'cadeau', 'mari', 'femme', 'copine',
            'copain', 'frère', 'soeur', 'sœur', 'mère', 'père', 'enfant', 'taille', 'pointure', 'couleur', 'ou', 'qui', 'tu', 'parles', 'parle', 'tunisien', 'fondateur', 'fondateurs', 'créé', 'cree', 'es', 'suis'],
        'en' => ['i', 'want', 'need', 'looking', 'for', 'the', 'show', 'me', 'hello', 'hi', 'hey', 'under', 'between',
            'and', 'cheap', 'cheaper', 'what', 'is', 'do', 'you', 'have', 'price', 'order', 'my', 'with', 'red', 'black',
            'white', 'shoes', 'dress', 'bag', 'any', 'some', 'please', 'thanks', 'less', 'than', 'how', 'where', 'track',
            'seller', 'sell', 'store', 'account', 'password', 'return', 'refund', 'pay', 'forgot', 'become', 'much',
            'orders', 'categories', 'complaint', 'can', 'does', 'work', 'wrong', 'received', 'broken', 'damaged', 'size',
            'item', 'delivery', 'login', 'sign', 'wallet', 'coupon', 'plans', 'commission', 'products', 'add', 'status',
            'mean', 'gift', 'brother', 'sister', 'wife', 'husband', 'girlfriend', 'boyfriend', 'mom', 'dad', 'sneakers', 'who', 'created', 'made', 'owns', 'speak', 'tunisian', 'founder', 'founders', 'are', 'about'],
    ],

    // ── Recipients: "for whom" → gender ──────────────────────────────────
    // recipient => [gender, words]
    'recipients' => [
        'girlfriend' => ['women', ['sa7ebti', 'sahebti', 'sa7bti', 'sahbti', 'ma copine', 'copine', 'girlfriend', 'صاحبتي', 'صحبتي', 'صاحبة']],
        'wife'       => ['women', ['mrati', 'mra mte3i', 'ma femme', 'ma moitié', 'my wife', 'wife', 'مرتي', 'زوجتي']],
        'mother'     => ['women', ['omi', 'ommi', 'oumi', 'ma mère', 'ma mere', 'maman', 'my mom', 'my mother', 'mom', 'mother', 'mama', 'أمي', 'امي', 'ماما', 'والدتي']],
        'sister'     => ['women', ['okhti', 'ma soeur', 'ma sœur', 'my sister', 'sister', 'أختي', 'اختي', 'اخت']],
        'friend_f'   => ['women', ['sa7beti', 'my female friend', 'mon amie', 'ma copine']],
        'boyfriend'  => ['men',   ['sa7bi', 'sahbi', 'sa7bi mte3i', 'mon copain', 'mon petit ami', 'boyfriend', 'صاحبي', 'صحبي']],
        'husband'    => ['men',   ['rajli', 'raajli', 'mon mari', 'mon époux', 'my husband', 'husband', 'راجلي', 'زوجي']],
        'father'     => ['men',   ['bouya', 'baba', 'mon père', 'mon pere', 'papa', 'my dad', 'my father', 'dad', 'father', 'بوية', 'بابا', 'والدي']],
        'brother'    => ['men',   ['khouya', 'mon frère', 'mon frere', 'my brother', 'brother', 'خويا', 'اخويا', 'أخي']],
        'girl'       => ['kids_girl', ['bent', 'bnet', 'binti', 'ma fille', 'fille', 'my daughter', 'daughter', 'little girl', 'بنت', 'بنتي']],
        'boy'        => ['kids_boy',  ['weld', 'wlid', 'oulid', 'mon fils', 'garçon', 'garcon', 'my son', 'son', 'little boy', 'ولد', 'ولدي']],
        'child'      => ['kids', ['ouled', 'oulad', 'wled', 'wlad', 'drari', 'sghar', 'sghir', 'sghira', 'enfant', 'enfants', 'bébé', 'bebe', 'kids', 'kid', 'child', 'children', 'baby', 'أولاد', 'اولاد', 'ولاد', 'درارى', 'طفل', 'أطفال', 'اطفال', 'صغار', 'صغير', 'صغيرة', 'بيبي']],
    ],

    // Explicit gender (regex fragments, run on the normalised message)
    'gender' => [
        'women' => ['femme', 'femmes', 'pour elle', 'for her', 'for women', 'women', 'womens', "women's", 'woman', 'ladies', 'lady',
                    'mra', 'mara', 'nsa', 'nessa', 'nissa', 'nisa', 'lel nsa', 'lel nisa', 'lel mra', 'نساء', 'نسائي', 'نسائية', 'مرا', 'حريمي', 'سيدات'],
        'men'   => ['homme', 'hommes', 'pour lui', 'for him', 'for men', 'mens', "men's", 'rajel', 'rjel', 'rjal', 'rijal', 'rijel', 'lel rjel', 'lel rjal',
                    'lel rajel', 'رجال', 'رجالي', 'رجالية', 'راجل'],
    ],

    // ── Product words → category / subcategories ─────────────────────────
    // terms      words the customer may type (any language / script)
    // keywords   words used for ranking and as a text fallback when no subcategory matches
    // subs       regex fragments matched on subcategory slug / name / name_fr (real subcategories only)
    // sub_exclude regex fragments that remove a subcategory from `subs`
    // category   category slug, when the concept belongs to one category
    // gendered   true: Femme/Homme matters (the gender filter is applied)
    // broad      true: the concept is a whole category (clothes): category filter only, no subcategory / text filter
    // The word used in replies is in `reply_terms` below (approved real words only)
    'concepts' => [
        // BROAD: the whole Mode & Vêtements category ("dbach", "7wayej"). Never a keyword.
        'clothes' => [
            'terms'    => ['dbech', 'dbach', 'dbeche', 'dbesh', 'دبش', '7wayej', 'hwayej', '7weyej', 'hweyej', 'حوايج', 'حوائج', 'ta7wissa', 'lbes', 'lebsa', 'lbesa', 'لبسة', 'لبس', 'ملابس',
                           'vetement', 'vetements', 'vêtement', 'vêtements', 'habit', 'habits', 'fringues', 'fringue', 'clothes', 'clothing', 'clothe', 'outfit', 'outfits', 'tenue', 'tenues'],
            'keywords' => [],
            'subs'     => [],
            'sub_exclude' => [],
            'category' => 'fashion-clothing',
            'gendered' => true,
            'broad'    => true,
            'kids_categories' => ['kids-baby'],
            // Kids & Baby also holds toys and bottles: for clothes only its products with one of these words count
            'kids_name_words' => ['doudoune', 'veste', 'manteau', 'robe', 'pantalon', 'ensemble', 'tee', 'shirt', 'pull', 'sweat', 'body', 'bodysuit', 'pyjama', 'short', 'jean', 'jogging',
                                  'gilet', 'combinaison', 'chaussure', 'basket', 'sandale', 'pantoufle', 'chaussette', 'bonnet', 'casquette', 'tenue', 'vêtement', 'jacket', 'coat', 'dress', 'pants',
                                  'hoodie', 'sweater', 'cardigan', 'skirt', 'jupe', 'shoe', 'sandal', 'sock', 'cloth', 'wear', 'outfit', 'sleepsuit', 'romper', 'ملابس', 'حذاء'],
        ],
        'shoes' => [
            'terms'    => ['sabat', 'sabbat', 'sbat', 'sbabet', 'sbabat', 'sabbet', 'sbebet', 'صباط', 'سباط', 'سبابط', 'صبابط', 'سبّاط', 'سباطات',
                           'chaussure', 'chaussures', 'shoe', 'shoes', 'basket', 'baskets', 'sneaker', 'sneakers', 'espadrille', 'espadrilles',
                           'sandale', 'sandales', 'sandal', 'sandals', 'chlaka', 'chlek', 'شلاكة', 'شلايك', 'sbardila', 'sbadri', 'سبرديلة',
                           'mocassin', 'mocassins', 'escarpin', 'escarpins', 'talon', 'talons', 'heels', 'loafers', 'botte', 'bottes', 'bottine',
                           'bottines', 'boots', 'حذاء', 'احذية', 'أحذية', 'صندل', 'بوت', 'بوتس'],
            'keywords' => ['chaussure', 'shoe', 'حذاء', 'sneaker', 'basket', 'sandale'],
            'subs'     => ['\bshoes?\b', 'chauss', 'sneaker', '\bbaskets?\b', 'sandal', '\bboots?\b', '\bbottes?\b', 'bottine', 'escarpin', '\bheels?\b', 'loafer', 'mocassin', 'espadrille', 'slipper', 'chausson', 'flip.?flop', '\btongs?\b'],
            'sub_exclude' => ['\bsocks?\b', 'chaussette'],
            'category' => null,
            'gendered' => true,
            'child_subs' => ['kids', 'enfant', 'baby', 'bebe', 'bébé'],
        ],
        'dresses' => [
            'terms'    => ['robe', 'robes', 'rouba', 'robba', 'روبة', 'روبات', 'dress', 'dresses', 'فستان', 'فساتين'],
            'keywords' => ['robe', 'dress', 'فستان'],
            'subs'     => ['\bdress(es)?\b', '\brobes?\b'],
            'sub_exclude' => ['night', 'peignoir', 'bath', 'wedding', 'mari[ée]', 'bridal', 'chambre', 'dressing'],
            'category' => null,
            'gendered' => true,
        ],
        'pants' => [
            'terms'    => ['serwel', 'sirwel', 'serwal', 'سروال', 'سراويل', 'pantalon', 'pantalons', 'jean', 'jeans', 'trouser', 'trousers', 'pants', 'jogging', 'legging', 'leggings', 'جينز'],
            'keywords' => ['pantalon', 'jean', 'trousers', 'سروال'],
            'subs'     => ['pant', 'pantalon', '\bjeans?\b', 'trouser', 'jogg', 'legging'],
            'sub_exclude' => [],
            'category' => null,
            'gendered' => true,
        ],
        'jebba' => [
            'terms'    => ['jebba', 'jbba', 'جبة', 'جبب', 'jebbah', 'djellaba', 'gandoura', 'قندورة', 'kaftan', 'caftan', 'قفطان'],
            'keywords' => ['jebba', 'جبة', 'gandoura', 'djellaba', 'caftan'],
            'subs'     => ['jebba', 'jubba', 'djellaba', 'gandoura', 'kaftan', 'caftan', 'traditional', 'traditionnel'],
            'sub_exclude' => [],
            'category' => null,
            'gendered' => true,
        ],
        'coats' => [
            'terms'    => ['kabbout', 'kabbut', 'kabout', 'كبوط', 'قبوط', 'manteau', 'manteaux', 'veste', 'vestes', 'jacket', 'jackets', 'coat', 'coats', 'blouson', 'parka', 'doudoune', 'جاكيت', 'معطف'],
            'keywords' => ['manteau', 'veste', 'jacket', 'coat', 'كبوط'],
            'subs'     => ['\bcoats?\b', 'manteau', '\bjackets?\b', '\bvestes?\b', 'blouson', 'parka', 'doudoune', 'outerwear', 'blazer'],
            'sub_exclude' => [],
            'category' => null,
            'gendered' => true,
        ],
        'tshirts' => [
            'terms'    => ['ta9chira', 'tak9chira', 'تقشيرة', 'tshirt', 't-shirt', 'tee', 'polo', 'تيشرت', 'maillot'],
            'keywords' => ['t-shirt', 'tshirt', 'polo', 'تيشرت'],
            'subs'     => ['t.?shirt', '\bpolos?\b', '\btops?\b', 'maillot'],
            'sub_exclude' => [],
            'category' => null,
            'gendered' => true,
        ],
        'shirts' => [
            'terms'    => ['chemise', 'chemises', 'shirt', 'shirts', 'قميص', 'قمصان'],
            'keywords' => ['chemise', 'shirt', 'قميص'],
            'subs'     => ['chemise', '\bshirts?\b'],
            'sub_exclude' => ['t.?shirt', 'sweat'],
            'category' => null,
            'gendered' => true,
        ],
        'sweaters' => [
            'terms'    => ['maryoul', 'maryoulet', 'mriyoul', 'مريول', 'مراول', 'tricot', 'tricots', 'تريكو', 'sweat', 'sweats', 'sweatshirt', 'hoodie', 'hoodies', 'pull', 'pulls', 'pullover', 'sweater', 'sweaters', 'سويت', 'بلوفر'],
            'keywords' => ['sweat', 'hoodie', 'pull', 'sweater'],
            'subs'     => ['sweat', 'hoodie', '\bpulls?\b', 'sweater', 'pullover', 'cardigan', 'knitwear', 'tricot'],
            'sub_exclude' => ['yarn', 'crochet', 'knitting', 'pants', 'trouser', 'jogg', 'short', 'tracksuit'],
            'category' => null,
            'gendered' => true,
        ],
        'skirts' => [
            'terms'    => ['jupe', 'jupes', 'skirt', 'skirts', 'jipa', 'جيبة', 'تنورة'],
            'keywords' => ['jupe', 'skirt', 'جيبة'],
            'subs'     => ['\bjupes?\b', '\bskirts?\b'],
            'sub_exclude' => [],
            'category' => null,
            'gendered' => true,
        ],
        'bags' => [
            'terms'    => ['sac', 'sacs', 'sak', 'shanta', 'chanta', 'chanta', 'شنطة', 'شكارة', 'شنط', 'حقيبة', 'حقائب', 'bag', 'bags', 'handbag', 'cabas', 'besace', 'sacoche', 'backpack', 'sac à dos'],
            'keywords' => ['sac', 'bag', 'حقيبة', 'cabas', 'handbag'],
            'subs'     => ['\bbags?\b', '\bsacs?\b', 'handbag', 'backpack', 'cabas', 'besace', 'sacoche', 'pochette', 'clutch'],
            'sub_exclude' => ['sleeping', 'couchage', 'poubelle', 'trash', 'garbage', 'yoga'],
            'category' => null,
            'gendered' => true,
        ],
        'scarves' => [
            'terms'    => ['foulard', 'foulards', 'echarpe', 'écharpe', 'scarf', 'scarves', 'sefsari', 'سفساري', 'حجاب', 'hijab', 'وشاح'],
            'keywords' => ['foulard', 'écharpe', 'scarf', 'hijab'],
            'subs'     => ['foulard', 'echarpe', 'écharpe', 'scarf', 'hijab', 'voile'],
            'sub_exclude' => [],
            'category' => null,
            'gendered' => true,
        ],
        'jewelry' => [
            'terms'    => ['bijou', 'bijoux', 'jewelry', 'jewellery', 'collier', 'necklace', 'bracelet', 'bague', 'ring', 'boucles', 'خاتم', 'سوار', 'مجوهرات', 'عقد'],
            'keywords' => ['bijou', 'collier', 'bracelet', 'bague', 'jewelry'],
            'subs'     => ['jewel', 'bijou', 'collier', 'necklace', 'bracelet', '\bbagues?\b', 'earring', 'boucle'],
            'sub_exclude' => [],
            'category' => null,
            'gendered' => true,
        ],
        'watches' => [
            'terms'    => ['mongela', 'montre', 'montres', 'watch', 'watches', 'منقالة', 'منڨالة', 'ساعة', 'ساعات'],
            'keywords' => ['montre', 'watch', 'ساعة'],
            'subs'     => ['\bwatch(es)?\b', 'montre'],
            'sub_exclude' => ['smart', 'connect'],
            'category' => null,
            'gendered' => true,
        ],
        'makeup' => [
            'terms'    => ['maquillage', 'makeup', 'make-up', 'make up', 'ma9yage', 'مكياج', 'mascara', 'fond de teint', 'rouge à lèvres', 'rouge a levres', 'lipstick', 'vernis', 'ظلال'],
            'keywords' => ['maquillage', 'makeup', 'mascara', 'lipstick', 'مكياج'],
            'subs'     => ['makeup', 'maquill', '\blips?\b', 'mascara', 'foundation', 'nail', 'vernis', 'eye.?shadow', 'cosmetic'],
            'sub_exclude' => [],
            'category' => 'beauty-personal-care',
            'gendered' => false,
        ],
        'socks' => [
            'terms'    => ['klasset', 'kalsa', 'kalsat', 'klassat', 'chaussette', 'chaussettes', 'socks', 'sock', 'كلاسات', 'كلسة'],
            'keywords' => ['chaussette', 'socks', 'sock'],
            'subs'     => ['\\bsocks?\\b', 'chaussette'],
            'sub_exclude' => [],
            'category' => null,
            'gendered' => true,
        ],
        'pyjama' => [
            'terms'    => ['pyjama', 'pyjamas', 'pijama', 'pajama', 'pajamas', 'بيجاما', 'بيجامة'],
            'keywords' => ['pyjama', 'pajama'],
            'subs'     => ['pyjama', 'pajama', 'sleepwear', 'nightwear'],
            'sub_exclude' => [],
            'category' => null,
            'gendered' => true,
        ],
        'glasses' => [
            'terms'    => ['nadharat', 'nadhara', 'nadhra', 'lunettes', 'lunette', 'glasses', 'sunglasses', 'نضارات', 'نظارات', 'نضارة'],
            'keywords' => ['lunettes', 'sunglasses', 'glasses'],
            'subs'     => ['sunglass', 'lunette', 'eyewear', 'eyeglass'],
            'sub_exclude' => ['ware', 'verre', 'drink', 'cup', 'wine'],
            'category' => null,
            'gendered' => true,
        ],
        'accessories' => [
            'terms'    => ['accessoire', 'accessoires', 'accessory', 'accessories', 'اكسسوارات', 'إكسسوارات', 'اكسسوار'],
            'keywords' => ['accessoire', 'accessories'],
            'subs'     => ['accessor'],
            'sub_exclude' => ['phone', 'car', 'auto', 'pet', 'computer', 'gaming', 'kitchen', 'bath'],
            'category' => 'fashion-clothing',
            'gendered' => true,
        ],
        'perfume' => [
            'terms'    => ['parfum', 'parfums', 'perfume', 'fragrance', 'ri7a', 'rou7ya', 'ريحة', 'عطر', 'عطور'],
            'keywords' => ['parfum', 'perfume', 'عطر'],
            'subs'     => ['parfum', 'perfume', 'fragrance', 'cologne'],
            'sub_exclude' => ['diffuser', 'diffuseur', 'bougie', 'candle'],
            'category' => null,
            'gendered' => false,
        ],
        'phones' => [
            'terms'    => ['telifoun', 'téléphone', 'telephone', 'portable', 'phone', 'smartphone', 'mobile', 'تليفون', 'هاتف', 'iphone', 'samsung'],
            'keywords' => ['téléphone', 'phone', 'smartphone', 'هاتف'],
            'subs'     => ['phone', 'mobile'],
            'sub_exclude' => ['case', 'coque', 'accessor', 'charger', 'chargeur', 'holder', 'support'],
            'category' => 'electronics-tech',
            'gendered' => false,
        ],
        'honey' => [
            'terms'    => ['3asal', 'asal', '3assel', 'عسل', 'miel', 'honey'],
            'keywords' => ['miel', 'honey', 'عسل'],
            'subs'     => ['honey', 'miel'],
            'sub_exclude' => [],
            'category' => 'food-grocery',
            'gendered' => false,
        ],
        'oliveoil' => [
            'terms'    => ['zit', 'zitouna', 'زيت', 'زيتون', 'huile', "huile d'olive", 'olive oil', 'oil'],
            'keywords' => ['huile', 'olive', 'oil', 'زيت'],
            'subs'     => ['olive', 'huile', '\boils?\b'],
            'sub_exclude' => ['cosmetic', 'hair', 'cheveux', 'massage', 'essential'],
            'category' => 'food-grocery',
            'gendered' => false,
        ],
    ],

    // ── Words used in replies, per product type ──────────────────────────
    // ONLY words Tunisians really use. Where they say the French word, it is the French word. No invented plurals.
    // [singular, plural] per dialect; attr = the size question to ask: pointure (shoes), taille (clothes), none.
    'reply_terms' => [
        'clothes'     => ['tn_arabizi' => ['7wayej', '7wayej'], 'tn_arabic' => ['حوايج', 'حوايج'], 'fr' => ['vêtement', 'vêtements'], 'en' => ['clothing', 'clothes'], 'attr' => 'taille'],
        'shoes'       => ['tn_arabizi' => ['sabbat', 'sbabet'], 'tn_arabic' => ['سباط', 'سباط'], 'fr' => ['chaussure', 'chaussures'], 'en' => ['shoe', 'shoes'], 'attr' => 'pointure'],
        'dresses'     => ['tn_arabizi' => ['robe', 'robes'], 'tn_arabic' => ['روبة', 'روبات'], 'fr' => ['robe', 'robes'], 'en' => ['dress', 'dresses'], 'attr' => 'taille'],
        'pants'       => ['tn_arabizi' => ['serwel', 'sarawel'], 'tn_arabic' => ['سروال', 'سراويل'], 'fr' => ['pantalon', 'pantalons'], 'en' => ['pair of pants', 'pants'], 'attr' => 'taille'],
        'jebba'       => ['tn_arabizi' => ['jebba', 'jebba'], 'tn_arabic' => ['جبة', 'جبة'], 'fr' => ['jebba', 'jebbas'], 'en' => ['jebba', 'jebbas'], 'attr' => 'taille'],
        'coats'       => ['tn_arabizi' => ['kabbout', 'kabbout'], 'tn_arabic' => ['كبوط', 'كبوط'], 'fr' => ['manteau', 'manteaux'], 'en' => ['coat', 'coats'], 'attr' => 'taille'],
        'tshirts'     => ['tn_arabizi' => ['t-shirt', 't-shirts'], 'tn_arabic' => ['تيشرت', 'تيشرت'], 'fr' => ['t-shirt', 't-shirts'], 'en' => ['t-shirt', 't-shirts'], 'attr' => 'taille'],
        'shirts'      => ['tn_arabizi' => ['chemise', 'chemises'], 'tn_arabic' => ['سورية', 'سورية'], 'fr' => ['chemise', 'chemises'], 'en' => ['shirt', 'shirts'], 'attr' => 'taille'],
        'sweaters'    => ['tn_arabizi' => ['maryoul', 'maryoulet'], 'tn_arabic' => ['مريول', 'مراول'], 'fr' => ['pull', 'pulls'], 'en' => ['sweater', 'sweaters'], 'attr' => 'taille'],
        'skirts'      => ['tn_arabizi' => ['jupe', 'jupes'], 'tn_arabic' => ['جيبة', 'جيبات'], 'fr' => ['jupe', 'jupes'], 'en' => ['skirt', 'skirts'], 'attr' => 'taille'],
        'bags'        => ['tn_arabizi' => ['sac', 'sacs'], 'tn_arabic' => ['ساك', 'ساك'], 'fr' => ['sac', 'sacs'], 'en' => ['bag', 'bags'], 'attr' => 'none'],
        'scarves'     => ['tn_arabizi' => ['foulard', 'foulards'], 'tn_arabic' => ['فولار', 'فولار'], 'fr' => ['foulard', 'foulards'], 'en' => ['scarf', 'scarves'], 'attr' => 'none'],
        'jewelry'     => ['tn_arabizi' => ['bijou', 'bijoux'], 'tn_arabic' => ['بيجو', 'بيجو'], 'fr' => ['bijou', 'bijoux'], 'en' => ['jewel', 'jewelry'], 'attr' => 'none'],
        'watches'     => ['tn_arabizi' => ['montre', 'montres'], 'tn_arabic' => ['مونتر', 'مونتر'], 'fr' => ['montre', 'montres'], 'en' => ['watch', 'watches'], 'attr' => 'none'],
        'makeup'      => ['tn_arabizi' => ['maquillage', 'maquillage'], 'tn_arabic' => ['ماكياج', 'ماكياج'], 'fr' => ['maquillage', 'maquillage'], 'en' => ['makeup', 'makeup'], 'attr' => 'none'],
        'perfume'     => ['tn_arabizi' => ['parfum', 'parfums'], 'tn_arabic' => ['بارفان', 'بارفان'], 'fr' => ['parfum', 'parfums'], 'en' => ['perfume', 'perfumes'], 'attr' => 'none'],
        'phones'      => ['tn_arabizi' => ['portable', 'portables'], 'tn_arabic' => ['تليفون', 'تليفون'], 'fr' => ['téléphone', 'téléphones'], 'en' => ['phone', 'phones'], 'attr' => 'none'],
        'honey'       => ['tn_arabizi' => ['3asal', '3asal'], 'tn_arabic' => ['عسل', 'عسل'], 'fr' => ['miel', 'miel'], 'en' => ['honey', 'honey'], 'attr' => 'none'],
        'oliveoil'    => ['tn_arabizi' => ['zit zitouna', 'zit zitouna'], 'tn_arabic' => ['زيت زيتون', 'زيت زيتون'], 'fr' => ["huile d'olive", "huile d'olive"], 'en' => ['olive oil', 'olive oil'], 'attr' => 'none'],
        'socks'       => ['tn_arabizi' => ['klasset', 'klasset'], 'tn_arabic' => ['كلاسات', 'كلاسات'], 'fr' => ['chaussette', 'chaussettes'], 'en' => ['sock', 'socks'], 'attr' => 'taille'],
        'pyjama'      => ['tn_arabizi' => ['pyjama', 'pyjamas'], 'tn_arabic' => ['بيجاما', 'بيجاما'], 'fr' => ['pyjama', 'pyjamas'], 'en' => ['pajama', 'pajamas'], 'attr' => 'taille'],
        'glasses'     => ['tn_arabizi' => ['nadharat', 'nadharat'], 'tn_arabic' => ['نضارات', 'نضارات'], 'fr' => ['lunettes', 'lunettes'], 'en' => ['glasses', 'glasses'], 'attr' => 'none'],
        'accessories' => ['tn_arabizi' => ['accessoire', 'accessoires'], 'tn_arabic' => ['اكسسوار', 'اكسسوارات'], 'fr' => ['accessoire', 'accessoires'], 'en' => ['accessory', 'accessories'], 'attr' => 'none'],
        // Products in general (never an invented plural)
        '*'           => ['tn_arabizi' => ['produit', 'produits'], 'tn_arabic' => ['منتوج', 'منتوجات'], 'fr' => ['produit', 'produits'], 'en' => ['product', 'products'], 'attr' => 'none'],
    ],

    // Forms the bot must never write (invented words / plurals). A reply containing one is rejected and rewritten.
    'forbidden_words' => [
        'ta9chirat', 'taqchirat', 'ta9chirate', 'sweatet', 'sweatat', 'robouet', 'robat', 'chemisat', 'pulat', 'jipat', 'foularet', 'mongelat',
        'nan9a7lek', 'nan9a7lik', 'nan9a7lak', 'n9a7lek', 'nan9a7', 'ننقحلك', 'نقحلك',
        'chnayet', 'kababet', 'telifounet', 'mountejat', 'mountajat', 'sbabetat', '7wayejet', 'maryoulat', 'serwelat', 'bijoutat',
        'تقشيرات', 'شناطي', 'كبابط', 'منقالات',
    ],

    // Concept words that must never count as free-text keywords.
    'filler' => [
        'nheb', 'n7eb', 'nechri', 'nchri', 'el', 'lel', 'bel', 'mel', 'mte3', 'mta3', 'bech', 'barcha', 'chwaya', 'taw', 'tawa',
        'famma', 'fama', 'ya3tik', 'behi', 'bahi', 'win', 'wein', 'hedhi', 'hedha', 'hedhom', 'nlawej', 'nal9a', 'cadeau', 'gift',
        'pour', 'for', 'ma', 'mon', 'my', '7aja', 'haja', 'un', 'une', 'want', 'shoes', 'sa7ebti', 'sahebti', 'sa7bi', 'mrati', 'rajli', 'omi', 'okhti', 'khouya', 'bouya',
        'bent', 'weld', 'akther', 'a9al', 'ta7t', 'fo9', 'rkhis', 'ghali', 'o5ra', 'okhra', 'couleur', 'color', 'colour', 'taille',
        'pointure', 'size', 'dinar', 'dinars', 'dt', 'tnd',
        'fi', 'w', '3la', '3and', 'b', 'ma3', 'wala', 'ou', 'des', 'du', 'de', 'la', 'le', 'les', 'je', 'cherche', 'veux', 'acheter', 'achete', 'voudrais', 'i', 'to', 'buy', 'a', 'the', 'looking', 'hdiya', 'هدية', 'ya3tik', 'najem', 'tnajem', 'nal9a', 'nal9ou', 'nchouf', 'warini', 'chouf', 'show',
        'نحب', 'نشري', 'لصاحبتي', 'صاحبتي', 'مرتي', 'راجلي', 'متاع', 'باش', 'فما', 'برشا', 'باهي', 'ل', 'لل', 'و',
    ],

    // ── Colors ───────────────────────────────────────────────────────────
    // canonical => [words typed by customers, DB synonyms (matched on option values / product names)]
    'colors' => [
        'black'  => ['words' => ['ak7el', 'a7kal', 'ka7la', 'ka7el', 'ak7al', 'noir', 'noire', 'black', 'اسود', 'سوداء', 'كحل', 'كحلة', 'أكحل'],
                     'db'    => ['noir', 'black', 'أسود', 'اسود', 'كحل']],
        'white'  => ['words' => ['abyedh', 'bayda', 'byadha', 'abiadh', 'blanc', 'blanche', 'white', 'ابيض', 'بيضاء', 'أبيض'],
                     'db'    => ['blanc', 'white', 'أبيض', 'ابيض']],
        'red'    => ['words' => ['a7mer', '7amra', 'ahmar', 'rouge', 'red', 'احمر', 'حمراء', 'أحمر'],
                     'db'    => ['rouge', 'red', 'أحمر', 'احمر']],
        'blue'   => ['words' => ['azre9', 'zar9a', 'azrak', 'bleu', 'bleue', 'blue', 'ازرق', 'زرقاء', 'أزرق'],
                     'db'    => ['bleu', 'blue', 'أزرق', 'ازرق']],
        'green'  => ['words' => ['akhdhar', 'akhdher', 'akhdar', 'khadhra', 'vert', 'verte', 'green', 'اخضر', 'خضراء', 'أخضر'],
                     'db'    => ['vert', 'green', 'أخضر', 'اخضر']],
        'yellow' => ['words' => ['asfar', 'safra', 'jaune', 'yellow', 'اصفر', 'صفراء', 'أصفر'],
                     'db'    => ['jaune', 'yellow', 'أصفر', 'اصفر']],
        'pink'   => ['words' => ['roz', 'wardi', 'rose', 'pink', 'وردي', 'وردية'],
                     'db'    => ['rose', 'pink', 'وردي']],
        'grey'   => ['words' => ['ramadi', 'gris', 'grise', 'grey', 'gray', 'رمادي'],
                     'db'    => ['gris', 'grey', 'gray', 'رمادي']],
        'brown'  => ['words' => ['bonni', 'kahwi', 'marron', 'brown', 'بني', 'قهوي', 'قهوية'],
                     'db'    => ['marron', 'brown', 'بني', 'قهوي']],
        'beige'  => ['words' => ['beige', 'bij', 'بيج'],
                     'db'    => ['beige', 'بيج']],
        'purple' => ['words' => ['violet', 'mov', 'purple', 'بنفسجي'],
                     'db'    => ['violet', 'purple', 'mauve', 'بنفسجي']],
        'orange' => ['words' => ['orange', 'orenj', 'برتقالي'],
                     'db'    => ['orange', 'برتقالي']],
        'gold'   => ['words' => ['dhahabi', 'doré', 'dore', 'gold', 'golden', 'ذهبي'],
                     'db'    => ['doré', 'dore', 'gold', 'ذهبي']],
        'silver' => ['words' => ['fodhi', 'argent', 'argenté', 'silver', 'فضي'],
                     'db'    => ['argent', 'silver', 'فضي']],
    ],

    // Colors as said in each dialect, for the "other color" chip and replies
    'color_names' => [
        'black'  => ['fr' => 'noir', 'en' => 'black', 'tn_arabizi' => 'ak7el', 'tn_arabic' => 'كحلة', 'msa' => 'أسود'],
        'white'  => ['fr' => 'blanc', 'en' => 'white', 'tn_arabizi' => 'abyedh', 'tn_arabic' => 'بيضاء', 'msa' => 'أبيض'],
        'red'    => ['fr' => 'rouge', 'en' => 'red', 'tn_arabizi' => 'a7mer', 'tn_arabic' => 'حمراء', 'msa' => 'أحمر'],
        'blue'   => ['fr' => 'bleu', 'en' => 'blue', 'tn_arabizi' => 'azre9', 'tn_arabic' => 'زرقاء', 'msa' => 'أزرق'],
        'green'  => ['fr' => 'vert', 'en' => 'green', 'tn_arabizi' => 'akhdhar', 'tn_arabic' => 'خضراء', 'msa' => 'أخضر'],
        'yellow' => ['fr' => 'jaune', 'en' => 'yellow', 'tn_arabizi' => 'asfar', 'tn_arabic' => 'صفراء', 'msa' => 'أصفر'],
        'pink'   => ['fr' => 'rose', 'en' => 'pink', 'tn_arabizi' => 'roz', 'tn_arabic' => 'وردية', 'msa' => 'وردي'],
        'grey'   => ['fr' => 'gris', 'en' => 'grey', 'tn_arabizi' => 'ramadi', 'tn_arabic' => 'رمادية', 'msa' => 'رمادي'],
        'brown'  => ['fr' => 'marron', 'en' => 'brown', 'tn_arabizi' => 'bonni', 'tn_arabic' => 'بنية', 'msa' => 'بني'],
        'beige'  => ['fr' => 'beige', 'en' => 'beige', 'tn_arabizi' => 'beige', 'tn_arabic' => 'بيج', 'msa' => 'بيج'],
        'purple' => ['fr' => 'violet', 'en' => 'purple', 'tn_arabizi' => 'violet', 'tn_arabic' => 'بنفسجية', 'msa' => 'بنفسجي'],
        'orange' => ['fr' => 'orange', 'en' => 'orange', 'tn_arabizi' => 'orange', 'tn_arabic' => 'برتقالية', 'msa' => 'برتقالي'],
        'gold'   => ['fr' => 'doré', 'en' => 'gold', 'tn_arabizi' => 'dhahabi', 'tn_arabic' => 'دهبية', 'msa' => 'ذهبي'],
        'silver' => ['fr' => 'argenté', 'en' => 'silver', 'tn_arabizi' => 'fodhi', 'tn_arabic' => 'فضية', 'msa' => 'فضي'],
    ],

    // ── Price words ──────────────────────────────────────────────────────
    'price' => [
        'cheap'     => ['rkhis', 'rkhes', 'rkhisa', 'ar5es', 'r5is', 'arkhas', 'a9al soum', 'pas cher', 'pas chère', 'moins cher', 'moins chère',
                        'cheap', 'cheaper', 'cheapest', 'abordable', 'برخيص', 'رخيص', 'رخيصة', 'ارخص', 'أرخص', 'الأرخص'],
        'expensive' => ['ghali', 'ghalia', '8ali', 'a8la', 'aghla', 'premium', 'haut de gamme', 'luxe', 'luxury', 'plus cher', 'plus chère',
                        'غالي', 'غالية', 'أغلى', 'اغلى'],
        'promo'     => ['promo', 'promos', 'promotion', 'promotions', 'solde', 'soldes', 'sale', 'discount', 'remise', 'réduction', 'reduction',
                        'ta5fid', 'ta5fidh', 'takhfid', 'تخفيض', 'تخفيضات', 'برومو', 'en promo', 'flash'],
        'newest'    => ['jdid', 'jdida', 'nouveau', 'nouveautés', 'nouveautes', 'new', 'newest', 'latest', 'جديد'],
        'popular'   => ['populaire', 'populaires', 'popular', 'best seller', 'bestseller', 'plus vendu', 'plus vendus', 'machhour', 'مشهور'],
    ],

    // ── Texts per dialect ────────────────────────────────────────────────
    // dialects: fr, en, tn_arabizi (Latin Tunisian), tn_arabic (Arabic-script Tunisian), msa (formal Arabic)
    'templates' => [
        'found' => [
            'fr' => "J'ai trouvé {n} {what} pour vous{range}. {top}",
            'en' => 'I found {n} {what} for you{range}. {top}',
            'tn_arabizi' => 'L9itelek {n} {what}{range}. {top}',
            'tn_arabic' => 'لقيتلك {n} {what}{range}. {top}',
            'msa' => 'وجدت لك {n} {what}{range}. {top}',
        ],
        'top_pick' => [
            'fr' => 'Je vous conseille « {name} » ({price}).',
            'en' => 'I suggest "{name}" ({price}).',
            'tn_arabizi' => 'Ne9tara7lek "{name}" ({price}).',
            'tn_arabic' => 'نقترحلك "{name}" ({price}).',
            'msa' => 'أقترح عليك "{name}" ({price}).',
        ],
        // The size question depends on the product type: pointure for shoes, taille for clothes, nothing otherwise
        'ask_shoes' => [
            'fr' => 'Quelle pointure faites-vous ?', 'en' => 'What shoe size do you wear?',
            'tn_arabizi' => 'Chnowa el pointure li tlabes?', 'tn_arabic' => 'شنوة البوانتير اللي تلبس؟', 'msa' => 'ما هو مقاس حذائك؟',
        ],
        'ask_clothes' => [
            'fr' => 'Quelle taille (S, M, L, XL) préférez-vous ?', 'en' => 'Which size (S, M, L, XL) do you prefer?',
            'tn_arabizi' => 'Chnowa el taille li t7eb (S, M, L, XL)?', 'tn_arabic' => 'شنوة المقاس اللي تحب (S, M, L, XL)؟', 'msa' => 'ما المقاس الذي تفضله (S, M, L, XL)؟',
        ],
        'ask_other' => [
            'fr' => 'Quelle couleur ou quel budget avez-vous en tête ?', 'en' => 'Which color or budget do you have in mind?',
            'tn_arabizi' => 'Chnowa el couleur wala el budget li t7eb?', 'tn_arabic' => 'شنوة اللون ولا الميزانية اللي تحب؟', 'msa' => 'ما اللون أو الميزانية التي تريدها؟',
        ],
        'ask' => [
            'fr' => 'Quelle taille ou quelle couleur préférez-vous ?',
            'en' => 'Which size or color do you prefer?',
            'tn_arabizi' => 'Chnowa el pointure wala el couleur li t7eb?',
            'tn_arabic' => 'شنوة المقاس ولا اللون اللي تحب؟',
            'msa' => 'ما المقاس أو اللون الذي تفضله؟',
        ],
        'for' => [   // appended to the intro when the recipient is known
            'women' => ['fr' => 'pour elle', 'en' => 'for her', 'tn_arabizi' => 'lel sa7ebtek', 'tn_arabic' => 'لصاحبتك', 'msa' => 'لها'],
            'men'   => ['fr' => 'pour lui', 'en' => 'for him', 'tn_arabizi' => 'lel sa7bek', 'tn_arabic' => 'لصاحبك', 'msa' => 'له'],
        ],
        'range_max' => ['fr' => ' à moins de {max}', 'en' => ' under {max}', 'tn_arabizi' => ' b a9al men {max}', 'tn_arabic' => ' بأقل من {max}', 'msa' => ' بأقل من {max}'],
        'range_min' => ['fr' => ' à plus de {min}', 'en' => ' over {min}', 'tn_arabizi' => ' b akther men {min}', 'tn_arabic' => ' بأكثر من {min}', 'msa' => ' بأكثر من {min}'],
        'range_both' => ['fr' => ' entre {min} et {max}', 'en' => ' between {min} and {max}', 'tn_arabizi' => ' mel {min} l {max}', 'tn_arabic' => ' من {min} ل {max}', 'msa' => ' بين {min} و {max}'],
        'unisex_note' => [
            'fr' => 'Ça inclut aussi des modèles mixtes (unisexes).',
            'en' => 'This includes unisex models too.',
            'tn_arabizi' => 'Famma zeda modelet unisex (lel jouj).',
            'tn_arabic' => 'فما زادة موديلات يونيسكس (للجوج).',
            'msa' => 'تشمل النتائج أيضاً موديلات للجنسين.',
        ],
        'few_unisex' => [   // few results with a gender: more unisex exist
            'fr' => "Il y a peu de modèles marqués « {gender} » ; d'autres modèles mixtes (unisexes) existent, dites-moi si vous voulez les voir.",
            'en' => 'There are only a few items tagged "{gender}"; more unisex items exist if you want to see them.',
            'tn_arabizi' => 'Famma kan chwaya modelet "{gender}"; famma zeda unisex, 9olli kan t7eb tchoufhom.',
            'tn_arabic' => 'فما كان شوية موديلات "{gender}"؛ وفما زادة يونيسكس، قولي كان تحب تشوفهم.',
            'msa' => 'عدد المنتجات المخصصة "{gender}" قليل؛ وتوجد منتجات للجنسين إن أحببت رؤيتها.',
        ],
        'gender_word' => [
            'women' => ['fr' => 'femme', 'en' => 'women', 'tn_arabizi' => 'nsa', 'tn_arabic' => 'نساء', 'msa' => 'نساء'],
            'men'   => ['fr' => 'homme', 'en' => 'men', 'tn_arabizi' => 'rjel', 'tn_arabic' => 'رجال', 'msa' => 'رجال'],
            'kids'  => ['fr' => 'enfant', 'en' => 'kids', 'tn_arabizi' => 'sghar', 'tn_arabic' => 'أطفال', 'msa' => 'أطفال'],
        ],
        // Zero results: what was relaxed (honest) or nothing at all
        'relaxed' => [
            'color' => [
                'fr' => "Je n'ai pas trouvé en {color}, mais voici les mêmes articles dans d'autres couleurs.",
                'en' => "I couldn't find it in {color}, but here are the same items in other colors.",
                'tn_arabizi' => 'Ma l9itech b {color}, ama hedhom nafs el 7wayej b alwen o5ra.',
                'tn_arabic' => 'ما لقيتش باللون {color}، أما هاذوما نفس الحوايج بألوان أخرى.',
                'msa' => 'لم أجد باللون {color}، لكن هذه نفس المنتجات بألوان أخرى.',
            ],
            'size' => [
                'fr' => "Je n'ai pas trouvé la taille {size} en stock, mais voici les articles disponibles dans d'autres tailles.",
                'en' => "I couldn't find size {size} in stock, but here are the items in other sizes.",
                'tn_arabizi' => 'Ma l9itech {sizeword} {size}, ama hedhom el mawjoudin b o5ra.',
                'tn_arabic' => 'ما لقيتش {sizeword} {size}، أما هاذوما الموجودين بأخرى.',
                'msa' => 'لم أجد المقاس {size}، لكن هذه المنتجات بمقاسات أخرى.',
            ],
            'price' => [
                'fr' => "Je n'ai rien trouvé {range}, mais voici ce qui s'en approche.",
                'en' => "I couldn't find anything {range}, but these are close.",
                'tn_arabizi' => 'Ma l9itech chay {range}, ama hedhom 9rib.',
                'tn_arabic' => 'ما لقيتش شي {range}، أما هاذوما قريبين.',
                'msa' => 'لم أجد شيئاً {range}، لكن هذه قريبة منه.',
            ],
            'subcategory' => [
                'fr' => "Je n'ai pas exactement cela, mais voici des {what}{gender} proches de votre demande 👇",
                'en' => "I don't have exactly that, but here are {what}{gender} close to your request 👇",
                'tn_arabizi' => 'Ma l9itech exactement hedha, ama hedhom {what}{gender} 9rab lel talab mte3ek 👇',
                'tn_arabic' => 'ما لقيتش بالضبط هذا، أما هاذوما {what}{gender} قريبين لطلبك 👇',
                'msa' => 'لا توجد نتيجة مطابقة تماماً، لكن هذه {what}{gender} قريبة من طلبك 👇',
            ],
            'keyword' => [
                'fr' => "Je n'ai pas exactement cela, mais voici des {what}{gender} proches de votre demande 👇",
                'en' => "I don't have exactly that, but here are {what}{gender} close to your request 👇",
                'tn_arabizi' => 'Ma l9itech exactement hedha, ama hedhom {what}{gender} 9rab lel talab mte3ek 👇',
                'tn_arabic' => 'ما لقيتش بالضبط هذا، أما هاذوما {what}{gender} قريبين لطلبك 👇',
                'msa' => 'لا توجد نتيجة مطابقة تماماً، لكن هذه {what}{gender} قريبة من طلبك 👇',
            ],
        ],
        // Nothing at all in the category (+ gender): honest, then the most popular products of the nearest category
        'none_popular' => [
            'fr' => "Je n'ai pas de {what}{gender} pour le moment, mais voici les produits les plus populaires 👇",
            'en' => "I don't have any {what}{gender} right now, but here are the most popular products 👇",
            'tn_arabizi' => 'Ma 3andich {what}{gender} tawa, ama hedhom el produits eli yebi3ou barcha 👇',
            'tn_arabic' => 'ما عندي {what}{gender} توّا، أما هاذوما المنتوجات اللي يتباعو برشا 👇',
            'msa' => 'لا أملك {what}{gender} حالياً، لكن هذه أكثر المنتجات رواجاً 👇',
        ],
        'none' => [
            'fr' => "Désolé, je n'ai trouvé aucun(e) {what}{gender} pour le moment. Regardez la catégorie ou changez un critère.",
            'en' => "Sorry, I couldn't find any {what}{gender} right now. Try the category or change a filter.",
            'tn_arabizi' => 'Sama7ni, ma l9itech {what}{gender} tawa. Jarreb el 9ism wala beddel chay fel talab.',
            'tn_arabic' => 'سامحني، ما لقيتش {what}{gender} توّا. جرّب القسم ولا بدّل حاجة في الطلب.',
            'msa' => 'عذراً، لم أجد {what}{gender} حالياً. جرّب القسم أو غيّر أحد المعايير.',
        ],
        'none_gender' => [
            'women' => ['fr' => ' pour femme', 'en' => ' for women', 'tn_arabizi' => ' lel nsa', 'tn_arabic' => ' للنساء', 'msa' => ' للنساء'],
            'men'   => ['fr' => ' pour homme', 'en' => ' for men', 'tn_arabizi' => ' lel rjel', 'tn_arabic' => ' للرجال', 'msa' => ' للرجال'],
            'kids'  => ['fr' => ' pour enfant', 'en' => ' for kids', 'tn_arabizi' => ' lel sghar', 'tn_arabic' => ' للصغار', 'msa' => ' للأطفال'],
        ],
        'refine_none' => [
            'fr' => "Ce sont déjà les options les moins chères que j'ai pour cette recherche. Autre chose ?",
            'en' => 'Those are already the cheapest options I have for this search. Anything else?',
            'tn_arabizi' => 'Hedhom a5ess 7aja 3andi fel ba7th hedha. T7eb 7aja o5ra?',
            'tn_arabic' => 'هاذوما أرخص حاجة عندي في البحث هذا. تحب حاجة أخرى؟',
            'msa' => 'هذه أرخص الخيارات لدي في هذا البحث. هل تريد شيئاً آخر؟',
        ],
        'error' => [
            'fr' => 'Désolé, un problème technique est survenu. Réessayez dans un instant.',
            'en' => 'Sorry, something went wrong on our side. Please try again in a moment.',
            'tn_arabizi' => 'Sama7ni, sar mochkel technique. 3awed jarreb ba3d chwaya.',
            'tn_arabic' => 'سامحني، صارت مشكلة تقنية. عاود جرّب بعد شوية.',
            'msa' => 'عذراً، حدثت مشكلة تقنية. حاول مجدداً بعد قليل.',
        ],
        'greeting' => [
            'fr' => "Bonjour ! 👋 Je suis l'assistant shopping de Choose'Tounsi. Dites-moi ce que vous cherchez et votre budget, par ex. « chaussures entre 50 et 100 DT ».",
            'en' => "Hi! 👋 I'm Choose'Tounsi's shopping assistant. Tell me what you're looking for and your budget, e.g. \"shoes between 50 and 100 DT\".",
            'tn_arabizi' => "Aslema! 👋 Ena el assistant mte3 Choose'Tounsi. 9olli chnowa t7eb w9adech el budget, mathalan « sbabet mel 50 l 100 dinar ».",
            'tn_arabic' => 'أهلا وسهلا! 👋 أنا مساعد التسوق متاع Choose\'Tounsi. قلّي شنوة تحب وقداش ميزانيتك، مثلا «سباط من 50 ل 100 دينار».',
            'msa' => 'أهلاً بك! 👋 أنا مساعد التسوق في Choose\'Tounsi. أخبرني بما تبحث عنه وميزانيتك، مثلاً «حذاء بين 50 و 100 دينار».',
        ],
        'other' => [
            'fr' => "Je suis là pour vous aider à trouver des produits sur Choose'Tounsi. Dites-moi ce que vous cherchez, avec un budget si vous voulez.",
            'en' => "I'm here to help you find products on Choose'Tounsi. Tell me what you're looking for, with a budget if you like.",
            'tn_arabizi' => "Ena hna bech n3awnek tal9a produits fi Choose'Tounsi. 9olli chnowa t7eb, w zid el budget kan t7eb.",
            'tn_arabic' => 'أنا هنا باش نعاونك تلقى منتجات في Choose\'Tounsi. قلّي شنوة تحب، وزيد الميزانية كان تحب.',
            'msa' => 'أنا هنا لمساعدتك في إيجاد المنتجات على Choose\'Tounsi. أخبرني بما تريد مع الميزانية إن أحببت.',
        ],
        // Platform questions in Darija (the knowledge base only has fr / en / ar)
        'faq' => [
            'payment' => [
                'fr' => ['intro' => "Le paiement sur Choose'Tounsi se fait en espèces à la livraison.",
                         'steps' => [['title' => 'Paiement à la livraison', 'description' => 'Vous payez en espèces à la réception de votre commande. Rien à payer à l\'avance.']]],
                'en' => ['intro' => "Payment on Choose'Tounsi is cash on delivery.",
                         'steps' => [['title' => 'Cash on delivery', 'description' => 'You pay in cash when your order arrives. Nothing to pay in advance.']]],
                'msa' => ['intro' => 'الدفع في Choose\'Tounsi يكون نقداً عند الاستلام.',
                          'steps' => [['title' => 'الدفع عند الاستلام', 'description' => 'تدفع نقداً عند وصول طلبك. لا يوجد دفع مسبق.']]],
                'tn_arabizi' => ['intro' => "El khlas fi Choose'Tounsi ykoun cash 3and el istilem.",
                                 'steps' => [['title' => 'Khlas 3and el istilem', 'description' => 'Tkhalles cash wa9t twasselek el commande. Ma tkhalles chay 9bal.']]],
                'tn_arabic'  => ['intro' => 'الخلاص في Choose\'Tounsi يكون كاش عند الاستلام.',
                                 'steps' => [['title' => 'خلاص عند الاستلام', 'description' => 'تخلص كاش كي توصلك الطلبية. ما تخلص حتى حاجة 9bal.']]],
            ],
            'delivery' => [
                'fr' => ['intro' => 'La livraison est gratuite.',
                         'steps' => [['title' => 'Livraison gratuite', 'description' => 'Vous ne payez rien de plus pour la livraison.']]],
                'en' => ['intro' => 'Delivery is free.',
                         'steps' => [['title' => 'Free delivery', 'description' => 'You pay nothing extra for delivery.']]],
                'msa' => ['intro' => 'التوصيل مجاني.',
                          'steps' => [['title' => 'توصيل مجاني', 'description' => 'لا تدفع أي مبلغ إضافي مقابل التوصيل.']]],
                'tn_arabizi' => ['intro' => 'El livraison b blech (gratuite) l kol el commandes.',
                                 'steps' => [['title' => 'Livraison b blech', 'description' => 'Ma tkhalles chay zeyed 3al livraison.']]],
                'tn_arabic'  => ['intro' => 'التوصيل مجاني لكل الطلبيات.',
                                 'steps' => [['title' => 'توصيل مجاني', 'description' => 'ما تخلص حتى حاجة زايدة على التوصيل.']]],
            ],
        ],
    ],

    // More fixed texts (per dialect). {placeholders} are filled by ReplyComposer.
    'texts' => [
        'near_price' => [
            'fr' => "Je n'ai rien trouvé{range}. Par contre, nous avons {count} article(s) {span}. Voulez-vous élargir votre budget ?",
            'en' => "I couldn't find anything{range}. We do have {count} item(s) {span}. Would you like to widen your budget?",
            'tn_arabizi' => 'Ma l9itech chay{range}. Ama 3andna {count} produit {span}. T7eb nwasse3 el budget?',
            'tn_arabic' => 'ما لقيتش شي{range}. أما عندنا {count} منتج {span}. تحب نوسّع الميزانية؟',
            'msa' => 'لم أجد شيئاً{range}. لكن لدينا {count} منتج {span}. هل تريد توسيع الميزانية؟',
        ],
        'span_same' => ['fr' => 'à {min}', 'en' => 'at {min}', 'tn_arabizi' => 'b {min}', 'tn_arabic' => 'بـ {min}', 'msa' => 'بـ {min}'],
        'span_range' => ['fr' => 'entre {min} et {max}', 'en' => 'between {min} and {max}', 'tn_arabizi' => 'mel {min} l {max}', 'tn_arabic' => 'من {min} ل {max}', 'msa' => 'بين {min} و {max}'],
        'refine_end' => [
            'fr' => "C'est tout ce que j'ai pour cette recherche pour le moment. Autre chose ?",
            'en' => "That's everything I have for this search right now. Anything else?",
            'tn_arabizi' => 'Hedha kol 3andi fel ba7th hedha tawa. T7eb 7aja o5ra?',
            'tn_arabic' => 'هذا كل شي عندي في البحث هذا توّا. تحب حاجة أخرى؟',
            'msa' => 'هذا كل ما لدي لهذا البحث حالياً. هل تريد شيئاً آخر؟',
        ],
        'categories_intro' => [
            'fr' => 'Voici nos catégories — touchez-en une, ou dites-moi ce que vous cherchez :',
            'en' => 'Here are our categories — tap one to browse, or tell me what you need:',
            'tn_arabizi' => 'Hedhi el a9sem mte3na — o5tar wa7da, wala 9olli chnowa t7eb:',
            'tn_arabic' => 'هاذي الأقسام متاعنا — اضغط على قسم، ولا قلّي شنوة تحب:',
            'msa' => 'هذه أقسامنا — اختر قسماً، أو أخبرني بما تريد:',
        ],
        'login_orders' => [
            'fr' => 'Connectez-vous pour voir vos commandes et leur statut.',
            'en' => 'Please log in to see your orders and their status.',
            'tn_arabizi' => 'Od5el l compte mte3ek bech tchouf el commandes mte3ek w el 7ala mte3hom.',
            'tn_arabic' => 'ادخل لحسابك باش تشوف طلبياتك وحالتها.',
            'msa' => 'سجّل الدخول لترى طلباتك وحالتها.',
        ],
        'no_orders' => [
            'fr' => "Vous n'avez pas encore de commande. Voulez-vous que je vous aide à trouver un produit ?",
            'en' => "You don't have any orders yet. Want me to help you find something?",
            'tn_arabizi' => 'Ma 3andek 7atta commande tawa. T7eb n3awnek tal9a 7aja?',
            'tn_arabic' => 'ما عندك حتى طلبية توّا. تحب نعاونك تلقى حاجة؟',
            'msa' => 'ليس لديك طلبات بعد. هل تريد أن أساعدك في إيجاد منتج؟',
        ],
        'orders_one' => [
            'fr' => 'Voici votre dernière commande :', 'en' => 'Here is your latest order:',
            'tn_arabizi' => 'Hedhi akher commande mte3ek:', 'tn_arabic' => 'هاذي آخر طلبية متاعك:', 'msa' => 'هذه آخر طلبية لك:',
        ],
        'orders_many' => [
            'fr' => 'Voici vos {n} dernières commandes :', 'en' => 'Here are your {n} latest orders:',
            'tn_arabizi' => 'Hedhom akher {n} commandes mte3ek:', 'tn_arabic' => 'هاذوما آخر {n} طلبيات متاعك:', 'msa' => 'هذه آخر {n} طلبيات لك:',
        ],
        'too_fast' => [
            'fr' => 'Vous envoyez des messages trop vite. Patientez quelques secondes 🙏',
            'en' => "You're sending messages too quickly. Please wait a few seconds 🙏",
            'tn_arabizi' => 'Tab3ath barcha messages bel zerba. Stanna chwaya thwenni 🙏',
            'tn_arabic' => 'تبعث في رسائل برشا بالزربة. استنى شوية ثواني 🙏',
            'msa' => 'ترسل الرسائل بسرعة كبيرة. انتظر بضع ثوانٍ 🙏',
        ],
        'fallback_pick' => [   // template reply when the reply model is down
            'fr' => 'Cliquez sur une fiche pour voir les détails.', 'en' => 'Tap a card to see the details.',
            'tn_arabizi' => 'Dous 3al produit bech tchouf les détails.', 'tn_arabic' => 'اضغط على المنتج باش تشوف التفاصيل.', 'msa' => 'اضغط على المنتج لرؤية التفاصيل.',
        ],
    ],

    // Knowledge-base buttons in Arabizi (the knowledge base itself only has fr / en / Arabic script).
    // kb_chips: quick-reply key => [label, message sent when tapped]; the message must be understood by the rules.
    'kb_chips' => [
        'find_product'        => ['Lawej 3la 7aja', 'n7eb nlawej 3la 7aja'],
        'order_how'           => ['Kifech na3mel commande', 'kifech na3mel commande?'],
        'order_payment'       => ['Kifech nkhallas?', 'kifech nkhallas?'],
        'order_coupon'        => ['Coupons', 'kifech nesta3mel coupon?'],
        'track_order'         => ['Win commande mte3i', 'win el commande mte3i'],
        'order_statuses'      => ['7alet el commande', 'chnowa ya3ni 7alet el commande?'],
        'complaint_how'       => ['3andi mochkel', 'n7eb nraja3 commande'],
        'vendor_apply'        => ['N7eb nwalli bayee3', 'n7eb nbi3 3and Choose'],
        'vendor_plans'        => ['Plans el bayi3in', 'chnowa el abonnement mte3 el vendeur?'],
        'vendor_commission'   => ['Commission', '9adeh el commission?'],
        'vendor_add_products' => ['Nzid produits', 'kifech nzid produit?'],
        'account_signup'      => ['N3mel compte', 'kifech n3mel compte?'],
        'account_password'    => ['Nsit el mot de passe', 'nsit el mot de passe'],
        'account_wallet'      => ['El wallet', 'kifech yekhdem el wallet?'],
        'show_cheaper'        => ['Arkhas', 'rkhis akther'],
        'show_more'           => ['Zid chouf', 'warini o5rin'],
        'other_categories'    => ['9asmet o5ra', 'chnowa 3andkom?'],
    ],

    // Link buttons of the knowledge base in Arabizi, keyed by their English label
    'kb_labels' => [
        'Add products' => 'Nzid produits', 'Become a seller' => 'N7eb nwalli bayee3', 'Commission' => 'Commission', 'Contact us' => 'Contactina',
        'Coupons' => 'Coupons', 'Create account' => 'N3mel compte', 'Create an account' => 'N3mel compte', 'File a complaint' => 'N3mel reclamation',
        'Find a product' => 'Lawej 3la 7aja', 'Forgot password' => 'Nsit el mot de passe', 'Go to checkout' => 'Sfa7et el khlas',
        'How do I pay?' => 'Kifech nkhallas?', 'How to order' => 'Kifech na3mel commande', 'Log in' => 'Od5el', 'My addresses' => 'Les adresses mte3i',
        'My complaints' => 'Les reclamations mte3i', 'My orders' => 'Les commandes mte3i', 'My products' => 'Les produits mte3i',
        'My profile' => 'Profil mte3i', 'My subscription' => 'Abonnement mte3i', 'Open cart' => 'Fta7 el panier', 'Order statuses' => '7alet el commande',
        'Other categories' => '9asmet o5ra', 'Report a problem' => '3andi mochkel', 'Reset password' => 'Beddel el mot de passe',
        'See the plans' => 'Chouf el plans', 'Seller plans' => 'Plans el bayi3in', 'Shop now' => 'Tsawwa9', 'Show cheaper' => 'Arkhas',
        'Show others' => 'Zid chouf', 'Track my order' => 'Win commande mte3i', 'Wallet' => 'El wallet',
    ],

    // ── Chips (quick replies), per dialect: label + message sent when tapped ──
    'chips' => [
        'cheaper' => [
            'fr' => ['Moins cher', 'Montre-moi moins cher'], 'en' => ['Cheaper', 'Show me cheaper ones'],
            'tn_arabizi' => ['Arkhas', 'rkhis akther'], 'tn_arabic' => ['أرخص', 'وريني أرخص'], 'msa' => ['أرخص', 'أرني أرخص'],
        ],
        'other_color' => [  // {color} = next color word in that dialect
            'fr' => ['Autre couleur', 'en {color}'], 'en' => ['Other color', 'in {color}'],
            'tn_arabizi' => ['Couleur o5ra', 'bel {color}'], 'tn_arabic' => ['لون آخر', 'باللون {color}'], 'msa' => ['لون آخر', 'باللون {color}'],
        ],
        'pay' => [
            'fr' => ['Comment payer ?', 'Comment payer ?'], 'en' => ['How do I pay?', 'How do I pay?'],
            'tn_arabizi' => ['Kifech nkhallas?', 'kifech nkhallas?'], 'tn_arabic' => ['كيفاش نخلص؟', 'كيفاش نخلص؟'], 'msa' => ['كيف أدفع؟', 'كيف أدفع؟'],
        ],
        'more_in' => [  // link label; {what} = concept label, {gender} = gender word
            'fr' => 'Tous les {what}{gender}', 'en' => 'All {what}{gender}',
            'tn_arabizi' => 'Kol el {what}{gender}', 'tn_arabic' => 'كل ال{what}{gender}', 'msa' => 'كل ال{what}{gender}',
        ],
        'more_gender' => [  // appended to the link label: " lel nsa"
            'women' => ['fr' => ' pour femme', 'en' => ' for women', 'tn_arabizi' => ' lel nsa', 'tn_arabic' => ' للنساء', 'msa' => ' للنساء'],
            'men'   => ['fr' => ' pour homme', 'en' => ' for men', 'tn_arabizi' => ' lel rjel', 'tn_arabic' => ' للرجال', 'msa' => ' للرجال'],
        ],
        'categories' => [
            'fr' => ['Autres catégories', 'Quelles catégories avez-vous ?'], 'en' => ['Other categories', 'What categories do you have?'],
            'tn_arabizi' => ['9asmet o5ra', 'chnowa el a9sem li 3andkom?'], 'tn_arabic' => ['أقسام أخرى', 'شنية الأقسام اللي عندكم؟'], 'msa' => ['أقسام أخرى', 'ما هي الأقسام لديكم؟'],
        ],
        'find' => [
            'fr' => ['Trouver un produit', 'Je cherche un produit'], 'en' => ['Find a product', 'I am looking for a product'],
            'tn_arabizi' => ['Lawej 3la 7aja', 'n7eb nlawej 3la 7aja'], 'tn_arabic' => ['لوّج على منتج', 'نحب نلوّج على منتج'], 'msa' => ['ابحث عن منتج', 'أبحث عن منتج'],
        ],
        'track' => [
            'fr' => ['Suivre ma commande', 'Où est ma commande ?'], 'en' => ['Track my order', 'Track my order'],
            'tn_arabizi' => ['Win commande mte3i', 'win el commande mte3i'], 'tn_arabic' => ['وين طلبيتي', 'وين الطلبية متاعي؟'], 'msa' => ['أين طلبي', 'أين طلبي؟'],
        ],
        'checkout' => [
            'fr' => 'Aller au paiement', 'en' => 'Go to checkout', 'tn_arabizi' => 'Sfa7et el khlas', 'tn_arabic' => 'صفحة الخلاص', 'msa' => 'صفحة الدفع',
        ],
        'shop' => [
            'fr' => 'Voir la boutique', 'en' => 'Browse the shop', 'tn_arabizi' => 'Chouf el boutique', 'tn_arabic' => 'تصفّح المتجر', 'msa' => 'تصفّح المتجر',
        ],
    ],

    // ── Few-shot examples shown to Groq (customer message → ideal reply) ──
    'fewshot' => [
        'tn_arabizi' => [
            ['nheb nechri sabat el sa7ebti', 'Behi barcha! L9itelek sbabet mrigla lel sa7ebtek. "Sneakers Roz" b 79 DT behi barcha w yel7a9 3al chwaya. Chnowa el pointure mte3ha?'],
            ['n7eb robe ka7la a9al men 150', 'Ey famma robes ka7la a9al men 150 DT. "Robe Soiree Noire" b 129 DT hiya el a7sen lel ma3rouda. T7eb nchouflek couleur o5ra?'],
            ['9adeh soum el jebba?', 'El jebba "Jebba Tounsia" b 95 DT. Famma zeda "Jebba Brodé" b 120 DT. T7eb ta3ref akther 3al jebba?'],
            ['rkhis akther', 'Behi, hedhom a5ess 7aja l9itha: "Sandales Cuir" b 39 DT. Ta7ki 3al budget mte3ek bech n9arrab akther?'],
            ['bel abyedh', 'Ey, hedhom abyedh: "Basket Blanche" b 65 DT. Chnowa el pointure?'],
            ['chnowa el 7wayej lel 3id', 'Famma barcha 7wayej lel 3id. Sbabet wala robes wala jebba? 9olli chnowa t7eb w9adech el budget.'],
        ],
        'tn_arabic' => [
            ['نحب نشري سباط لصاحبتي', 'باهي برشا! لقيتلك سباط لصاحبتك. "Sneakers Rose" بـ 79 د.ت باهي برشا. شنوة المقاس متاعها؟'],
            ['قداش سوم الجبة؟', 'الجبة "Jebba Tounsia" بـ 95 د.ت. وفما زادة "Jebba Brodé" بـ 120 د.ت. تحب نعاونك أكثر؟'],
            ['نحب روبة كحلة أقل من 150', 'أيه فما روبات كحلة أقل من 150 د.ت. "Robe Soiree Noire" بـ 129 د.ت هي الأحسن للمناسبات. تحب لون آخر؟'],
            ['أرخص', 'باهي، هاذي أرخص حاجة لقيتها: "Sandales Cuir" بـ 39 د.ت. قولي ميزانيتك باش نقرّب أكثر.'],
        ],
        'msa' => [
            ['أريد حذاء لأختي', 'يسعدني ذلك! وجدت لها أحذية مناسبة. أنصحك بـ "Sneakers Rose" بسعر 79 د.ت. ما هو مقاسها؟'],
        ],
    ],
];
