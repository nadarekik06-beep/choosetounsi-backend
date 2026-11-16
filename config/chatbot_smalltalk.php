<?php

/**
 * Small-talk, identity and founders intents of the shopping chatbot.
 *
 * Rule-based (no Groq call), checked BEFORE product search. EDIT THIS FILE to add phrases or change replies.
 *
 *   - phrases are matched ignoring case, punctuation, spelling repeats ("salemmm", "aslema!!") and extra words
 *     around them ("salem khouya", "aslema ya bot");
 *   - Arabic phrases are normalised like the lexicon (hamza, ta marbuta ...);
 *   - dialects: fr, en, tn_arabizi (Tunisian in Latin letters), tn_arabic (Tunisian in Arabic script);
 *     msa uses the tn_arabic text.
 */
return [

    // ── Phrases ──────────────────────────────────────────────────────────

    // Words that open a conversation. A greeting followed by a product request is answered as a product search.
    'greeting' => [
        'salem', 'slm', 'aslema', 'asslema', '3aslema', 'ahla', 'ahla w sahla', 'ahla w sehla', 'ahlan', 'ahlan wa sahlan', 'marhba', 'marhbe',
        'salam', 'salamou alaykom', 'salem alikom', 'salam alikom', 'salam alaykom', 'salam alaykoum', 'salem alaykom', 'salamou 3alikom',
        'salem 3alikom', 'salam 3alikom', 'sba7 el khir', 'sba7 elkhir', 'msa el khir', 'msa elkhir', 'sba7 el 5ir', 'msa el 5ir',
        'السلام عليكم', 'سلام', 'عسلامة', 'أهلا', 'أهلا وسهلا', 'مرحبا', 'صباح الخير', 'مساء الخير', 'صباح الخير', 'سلام عليكم',
        'bonjour', 'bonsoir', 'salut', 'cc', 'coucou', 'slt', 'bjr', 'hello', 'hi', 'hey', 'hiya', 'good morning', 'good evening',
    ],
    // A greeting that asks for the "peace" answer: "W 3alikom essalem"
    'salam' => ['alikom', 'alaykom', 'alaykoum', '3alikom', '3alaykom', 'salamou', 'عليكم', 'عليكم'],
    // Words allowed around a greeting without making it a request
    'greeting_filler' => [
        'ya', 'bot', 'chatbot', 'assistant', 'khouya', 'sa7bi', 'sahbi', '3ziz', 'sidi', 'choosetounsi', 'tounsi', 'tout', 'le', 'monde', 'tous',
        'everyone', 'there', 'all', 'labes', 'labess', 'la bes', 'chnahwalek', 'chnowa 7alek', 'kifek', 'kifech enti', 'ca va', 'comment ca va', 'cv',
        'how are you', 'w', 'wa', 'el', 'bik', 'bikom', 'choose',
        'يا', 'خويا', 'صاحبي', 'عزيزي', 'لاباس', 'شنحوالك', 'كيفاش',
    ],

    'speaks_tounsi' => [
        'ta7ki tounsi', 't7ki tounsi', 'tahki tounsi', 'ta7ki bel tounsi', 'tahki bel tounsi', 't7ki bel tounsi', 'tfhem tounsi', 'tefhem tounsi',
        'ta3ref tounsi', 'ta3ref tounsi', 'ta7ki tounsia', 'تحكي تونسي', 'تفهم تونسي', 'تحكي بالتونسي', 'تعرف تونسي',
        'tu parles tunisien', 'parles tu tunisien', 'parles tunisien', 'tu parle tunisien', 'do you speak tunisian', 'speak tunisian',
        'can you speak tunisian',
    ],

    // Founders: a phrase on its own, OR one word of each of who + did + brand
    'founders' => [
        'any'   => ['chkoun fondateur', 'chkoun les fondateurs', 'chkoun el fondateur', 'chkoun moassis', 'شكون المؤسس', 'شكون مؤسس', 'fondateur', 'fondateurs',
                    'fondatrice', 'founder', 'founders', 'co founder', 'cofounder', 'les fondateurs', 'المؤسسين'],
        'who'   => ['chkoun', 'chkoon', 'chkon', 'chkoun li', 'شكون', 'qui', 'who'],
        'did'   => ['amel', '3mal', '3amal', 'sna3', 'sne3', 'moula', 'wara', 'ta3ref', 'عمل', 'صنع', 'وراء', 'مولى', 'cree', 'a cree', 'fait', 'a fait',
                    'created', 'made', 'owns', 'owner', 'built', 'started', 'behind', 'proprietaire', 'derriere', 'lance'],
        'brand' => ['choosetounsi', 'choose tounsi', 'choose tounsi', 'ct', 'el site', 'site', 'plateforme', 'platform', 'website', 'el plateforme',
                    'الموقع', 'المنصة', 'ماركت', 'choose'],
    ],

    'identity' => [
        'chkoun enti', 'chkoun inti', 'chkoun ent', 'chnowa enti', 'chnowa inti', 'enti chkoun', 'inti chkoun', 'chnowa ta3mel', 'chnowa tnajem ta3mel',
        'chnowa tnajem', 'chnowa el 7aja eli tnajem ta3mellha', 'شكون إنت', 'شكون انت', 'شكون إنتي', 'شنوة تعمل', 'شنوا تعمل', 'شنوة تنجم تعمل',
        'qui es tu', 'tu es qui', 'qui etes vous', 'que sais tu faire', 'que fais tu', 'que peux tu faire', 'tu fais quoi', 'tu peux faire quoi',
        'who are you', 'what can you do', 'what do you do', 'what are you',
    ],

    'thanks' => [
        'y3aychek', 'ya3aychek', '3aychek', '3ayechek', 'ya3tik essa7a', 'ya3tik sa7a', 'ya3tik el sa7a', 'yatik essa7a', 'barakallah ou fik', 'barakallahou fik',
        'merci', 'mercii', 'mersi', 'merci beaucoup', 'choukran', 'chokran', 'shokran', 'chukran', 'thanks', 'thank you', 'thx', 'ty',
        'شكرا', 'شكراً', 'يعطيك الصحة', 'يعيشك', 'عيشك',
    ],

    'bye' => [
        'bslema', 'beslama', 'bsslema', 'bessalama', 'besslama', 'b salama', 'ciao', 'au revoir', 'a bientot', 'bye', 'bye bye', 'goodbye', 'see you',
        'tawa nchoufek', 'nchoufek', 'بالسلامة', 'بسلامة', 'مع السلامة',
    ],

    // ── Replies ──────────────────────────────────────────────────────────
    // {about} etc. are not used: facts are fixed text. Founders: only these facts, nothing invented.
    'about_route' => '/about',

    'replies' => [
        'greeting' => [
            'tn_arabizi' => "Ahla w sahla! 👋 Ena l'assistant mta3 Choose'Tounsi, hna bech n3awnek tel9a el produit eli t7eb 3lih b sohoula w fi asra3 wa9t. 9olli chnowa tlawej, w kan t7eb zid el budget (ex. « n7eb sabat lel nsa a9al men 100 DT »).",
            'tn_arabic'  => "أهلا وسهلا! 👋 أنا المساعد متاع Choose'Tounsi، هنا باش نعاونك تلقى المنتوج اللي تحب عليه بسهولة وفي أسرع وقت. قلّي شنوّة تلوّج، وكان تحب زيد الميزانية (مثلا « نحب سباط للنساء أقل من 100 دينار »).",
            'fr'         => "Bonjour ! 👋 Je suis l'assistant de Choose'Tounsi : je suis là pour vous aider à trouver facilement et très rapidement le produit que vous cherchez. Dites-moi ce que vous cherchez et, si vous voulez, votre budget (ex. « chaussures pour femme à moins de 100 DT »).",
            'en'         => "Hi! 👋 I'm Choose'Tounsi's assistant: I'm here to help you find the product you want easily and very fast. Tell me what you're looking for and, if you like, your budget (e.g. \"women's shoes under 100 DT\").",
        ],
        // First words of the greeting when the customer said "salam alaykom"
        'salam' => [
            'tn_arabizi' => 'W 3alikom essalem!', 'tn_arabic' => 'وعليكم السلام!', 'fr' => 'Wa alaykum assalam !', 'en' => 'Wa alaykum assalam!',
        ],
        // Short hello put in front of a product answer ("salem, n7eb sabat")
        'greet_short' => [
            'tn_arabizi' => 'Ahla w sahla! 👋', 'tn_arabic' => 'أهلا وسهلا! 👋', 'fr' => 'Bonjour ! 👋', 'en' => 'Hi! 👋',
        ],
        'speaks_tounsi' => [
            'tn_arabizi' => 'Ay na7ki tounsi w najem n3awnek bech tel9a produit eli t7eb alih fi asra3 wa9t 😊',
            'tn_arabic'  => 'إي نحكي تونسي ونجّم نعاونك باش تلقى المنتوج اللي تحب عليه في أسرع وقت 😊',
            'fr'         => "Oui, je parle tunisien et je peux t'aider à trouver le produit que tu cherches le plus rapidement possible 😊",
            'en'         => 'Yes, I speak Tunisian and I can help you find the product you want as fast as possible 😊',
        ],
        'founders' => [
            'tn_arabizi' => "Choose'Tounsi 3amlouh Abderahmen w Nada 🇹🇳, bech yse77lou 3ala ennes tel9a el produits eli t7ebhom 3and bayé3a tounsiyin, b sohoula w fi asra3 wa9t. Ta3ref akther 3lina fi page « Chkoun a7na ».",
            'tn_arabic'  => "Choose'Tounsi عملوه Abderahmen وNada 🇹🇳، باش يسهّلوا على الناس تلقى المنتوجات اللي تحبهم عند بايعين تونسيين، بسهولة وفي أسرع وقت. تعرف أكثر علينا في صفحة « شكون أحنا ».",
            'fr'         => "Choose'Tounsi a été créé par Abderahmen et Nada 🇹🇳, pour aider les Tunisiens à trouver facilement et très rapidement les produits qu'ils cherchent auprès de vendeurs tunisiens. Pour en savoir plus, rendez-vous sur la page « À propos ».",
            'en'         => "Choose'Tounsi was created by Abderahmen and Nada 🇹🇳, to help people find the products they want from Tunisian sellers, easily and very fast. Learn more on our « About us » page.",
        ],
        'founders_chip' => [
            'tn_arabizi' => 'Chkoun a7na', 'tn_arabic' => 'شكون أحنا', 'fr' => 'À propos', 'en' => 'About us',
        ],
        'identity' => [
            'tn_arabizi' => "Ena l'assistant mta3 Choose'Tounsi 🤖. N3awnek tel9a el produit eli t7eb 3lih b sohoula w fi asra3 wa9t. Jarreb par exemple : « n7eb sabat lel nsa a9al men 100 DT » wala « robe ka7la ».",
            'tn_arabic'  => "أنا المساعد متاع Choose'Tounsi 🤖. نعاونك تلقى المنتوج اللي تحب عليه بسهولة وفي أسرع وقت. جرّب مثلا : « نحب سباط للنساء أقل من 100 دينار » ولا « نحب روبة كحلة ».",
            'fr'         => "Je suis l'assistant de Choose'Tounsi 🤖. Je vous aide à trouver facilement et très rapidement le produit que vous cherchez. Essayez par exemple : « chaussures pour femme à moins de 100 DT » ou « robe noire ».",
            'en'         => "I'm Choose'Tounsi's assistant 🤖. I help you find the product you want easily and very fast. Try for example: \"women's shoes under 100 DT\" or \"black dress\".",
        ],
        'thanks' => [
            'tn_arabizi' => 'Y3aychek! 😊 Kan t7eb 7aja o5ra, ena hna.',
            'tn_arabic'  => 'يعيشك! 😊 كان تحب حاجة أخرى، أنا هنا.',
            'fr'         => "Avec plaisir ! 😊 Je reste là si vous avez besoin d'autre chose.",
            'en'         => "You're welcome! 😊 I'm here if you need anything else.",
        ],
        'bye' => [
            'tn_arabizi' => 'Bslema! 👋 Nchoufek 9rib fi Choose\'Tounsi.',
            'tn_arabic'  => 'بالسلامة! 👋 نشوفك قريب في Choose\'Tounsi.',
            'fr'         => "Au revoir ! 👋 À bientôt sur Choose'Tounsi.",
            'en'         => "Bye! 👋 See you soon on Choose'Tounsi.",
        ],
        // A message that is not about a product (or too unclear): ask, with example chips
        'unclear' => [
            'tn_arabizi' => "Ma fhemtekch behi 😅. 9olli chnowa t7eb tlawej 3lih (ex. « n7eb sabat lel nsa » wala « robe ka7la a9al men 150 DT »).",
            'tn_arabic'  => "ما فهمتكش باهي 😅. قلّي شنوة تحب تلوّج عليه (مثلا « نحب سباط للنساء » ولا « نحب روبة كحلة أقل من 150 دينار »).",
            'fr'         => "Je n'ai pas bien compris 😅. Dites-moi ce que vous cherchez (ex. « chaussures pour femme » ou « robe noire à moins de 150 DT »).",
            'en'         => "I didn't quite get that 😅. Tell me what you're looking for (e.g. \"women's shoes\" or \"black dress under 150 DT\").",
        ],
    ],

    // Example searches offered as chips: [label, message sent]
    'example_chips' => [
        'tn_arabizi' => [['Sbabet lel nsa', 'n7eb sabat lel nsa'], ['Robe ka7la', 'n7eb robe ka7la']],
        'tn_arabic'  => [['سباط للنساء', 'نحب سباط للنساء'], ['روبة كحلة', 'نحب روبة كحلة']],
        'fr'         => [['Chaussures femme', 'chaussures pour femme'], ['Robe noire', 'robe noire']],
        'en'         => [["Women's shoes", 'shoes for women'], ['Black dress', 'black dress']],
    ],
];
