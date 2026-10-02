<?php

namespace App\Services\ProductCopy;

use Carbon\CarbonImmutable;

/**
 * Builds the Groq prompt for product descriptions.
 *
 * Variety does not come from temperature alone: every variant gets a different
 * opening strategy and closing style, drawn at random on each request, so
 * "regenerate" also changes the shape of the copy, not just a few words.
 *
 * Brief (see DescriptionGenerator::brief()):
 *   name, store, category, subcategory, price, promo{percent, final, ends_at, flash},
 *   attributes{label: value}, option_groups{type: [values]}, variants[], pack{quantity, contents}, occasions[],
 *   notes, draft, keywords[], brand_voice{voice, keywords[]}
 */
class DescriptionPrompt
{
    /** Opening strategies; `offer` is only used when the product is on promotion. */
    public const HOOKS = [
        'detail'   => 'Open with the single most striking concrete fact from PRODUCT DATA (a material, a measurement, a feature) and what it changes for the buyer.',
        'scene'    => 'Open with a short, specific moment of use in Tunisia (a place, a time of day, a season or an occasion that genuinely fits this product).',
        'question' => 'Open with one direct question about a real need or annoyance the buyer has, which this product answers.',
        'contrast' => 'Open with a contrast: what usually disappoints with this kind of product, then how this one avoids it, using only facts from PRODUCT DATA.',
        'buyer'    => 'Open by naming precisely who it is for and in which situation, then why it suits them.',
        'offer'    => 'Open with the offer itself: the discount, the new price and the end date when known.',
    ];

    public const CLOSINGS = [
        'choice'   => 'End by helping the buyer choose (a colour, a size, a quantity, a pack) and invite them to add it to the cart.',
        'occasion' => 'End by tying the order to a fitting moment (an occasion, a season, a gift) and invite them to order in time.',
        'direct'   => 'End with a short, confident invitation to order, built around the main benefit.',
    ];

    private const TONES = [
        'professional' => [
            'rules' => 'Precise, calm, factual. Medium-length sentences. Lead with the most useful fact. No exclamation marks. Formal address ("vous" in French).',
            'example' => [
                'en' => 'Keeps water cold for 24 hours, even on a July afternoon in Tozeur. The 750 ml double-wall steel bottle fits a standard car cup holder and closes with a leak-proof screw cap.',
                'fr' => 'Votre eau reste fraîche 24 heures, même un après-midi de juillet à Tozeur. La gourde en acier double paroi de 750 ml se glisse dans un porte-gobelet standard et se ferme avec un bouchon vissé étanche.',
                'ar' => 'ماؤك يبقى باردًا 24 ساعة، حتى في ظهيرة جويلية بتوزر. قارورة من الفولاذ بجدار مزدوج سعتها 750 مل، تناسب حامل الأكواب في السيارة وتُغلق بغطاء لولبي لا يسرّب.',
            ],
        ],
        'friendly' => [
            'rules' => 'Warm and conversational, like a shop owner chatting with a regular customer. Short sentences, everyday words, second person, a light touch of humour is welcome. At most one exclamation mark. Informal address ("tu" in French).',
            'example' => [
                'en' => 'Groceries, your laptop, a towel for the beach after work: this canvas tote takes all of it without complaining. Throw it in the wash and it is ready to go again tomorrow.',
                'fr' => "Les courses, l'ordi, une serviette pour la plage après le boulot : ce cabas en toile prend tout sans broncher. Un tour en machine et il repart avec toi le lendemain.",
                'ar' => 'مشتريات السوق، حاسوبك، ومنشفة للبحر بعد العمل: هذه الحقيبة القماشية تتّسع لكل شيء دون تذمّر. اغسلها في الغسالة وستكون جاهزة لمرافقتك غدًا من جديد.',
            ],
        ],
        'luxury' => [
            'avoid_hooks' => ['question', 'contrast'],   // luxury copy states, it does not ask or compare
            'rules' => 'Restrained, sensory, unhurried. Few adjectives, precise nouns (materials, finishes, gestures, details). Let details imply value. Never mention price, discounts, "affordable" or urgency. No exclamation marks.',
            'example' => [
                'en' => 'Full-grain calfskin, stitched with waxed linen thread. Firm at first, it slowly takes the shape of your pocket and a patina no one else will have.',
                'fr' => "Cuir de veau pleine fleur, cousu au fil de lin ciré. Ferme au début, il prend peu à peu la forme de votre poche et une patine qui n'appartient qu'à vous.",
                'ar' => 'جلد عجل طبيعي كامل الحبيبات، مخيط بخيط كتان مشمّع. متماسك في البداية، ثم يأخذ مع الوقت شكل جيبك ولمعةً لا تشبه سواها.',
            ],
        ],
        'promo' => [
            'rules' => 'Energetic and direct. When PRODUCT DATA has a promotion, put the discount, the new price and the end date in the first sentence. Short punchy sentences, strong verbs, at most one exclamation mark. Urgency must come ONLY from real data (promotion end date, stock left); with no promotion, build momentum from a fitting season or occasion instead. Never invent scarcity, deadlines or discounts.',
            'example' => [
                'en' => '-20% until Sunday: the rechargeable desk fan drops to 63 DT. Three speeds and 6 hours on one charge, enough for a whole August afternoon. Only 12 left at this price.',
                'fr' => "-20 % jusqu'à dimanche : le ventilateur de bureau rechargeable passe à 63 DT. Trois vitesses, 6 heures d'autonomie, de quoi tenir tout un après-midi d'août. Plus que 12 pièces à ce prix.",
                'ar' => 'تخفيض 20% حتى يوم الأحد: المروحة المكتبية القابلة للشحن بـ63 د.ت فقط. ثلاث سرعات و6 ساعات تشغيل بشحنة واحدة، تكفي لظهيرة كاملة من حرّ أوت. بقيت 12 قطعة فقط بهذا السعر.',
            ],
            'example_note' => '(the discount, end date and stock in this example came from that product\'s data)',
        ],
        'artisanal' => [
            'rules' => 'Rooted and respectful of Tunisian craft: the maker, the region, the technique, the material, the time it takes, and how the object lives in a Tunisian home. Use place names and craft words ONLY when PRODUCT DATA or the seller notes give them. If the data does not say the product is handmade or where it comes from, do not claim it: talk about tradition of use instead.',
            'example' => [
                'en' => 'Painted by hand in a Nabeul workshop, every stroke of cobalt blue differs slightly from the plate next to it. On Friday it carries the couscous; the rest of the week it dresses a wall.',
                'fr' => "Peinte à la main dans un atelier de Nabeul, chaque touche de bleu cobalt diffère légèrement de l'assiette voisine. Le vendredi, elle porte le couscous ; le reste de la semaine, elle habille un mur.",
                'ar' => 'رُسمت باليد في ورشة بنابل، وكل لمسة من الأزرق الكوبالتي تختلف قليلًا عن الصحن المجاور. يوم الجمعة تحمل الكسكسي، وبقية الأسبوع تزيّن الجدار.',
            ],
            'example_note' => '(the handmade origin in Nabeul came from that product\'s data)',
        ],
    ];

    private const LANGUAGES = [
        'en' => 'English. Write natively in clear, modern international English (not a translation). Prices like "85 DT" or "84.500 DT".',
        'fr' => 'French. Write natively as a Tunisian francophone copywriter would (never a translation from English). French typography: a space before : ; ! ? and %, guillemets « » for quotes, decimal comma. Prices like "85 DT" or "84,500 DT".',
        'ar' => 'Modern Standard Arabic. Write natively for Tunisian buyers as a Tunisian copywriter would: simple, modern, warm, never literary and never translated word for word. Use Western digits (0-9), the Arabic comma "،" and question mark "؟", prices like "85 د.ت", Tunisian month names (جانفي، فيفري، مارس، أفريل، ماي، جوان، جويلية، أوت، سبتمبر، أكتوبر، نوفمبر، ديسمبر). Express attribute values in Arabic (Cotton → قطن), but keep brand names and model numbers in Latin script.',
    ];

    /** Clichés the model must never write. Also enforced after generation by DescriptionCleaner. */
    public const BANNED = [
        'en' => [
            'high quality', 'high-quality', 'top quality', 'ideal for', 'the perfect', 'perfect fit', 'premium quality', 'top-notch', 'best-in-class', 'perfect for',
            'elevate your', 'look no further', 'must-have', 'game-changer', 'game changer', 'next level', 'unleash',
            'whether you\'re', 'whether you are', 'introducing', 'discover our', 'discover the', 'imagine', 'stand out from the crowd',
            'exceptional quality', 'unparalleled', 'second to none', 'timeless elegance', 'don\'t miss out', 'don\'t wait',
            'look and feel your best', 'crafted with care', 'the seller', 'seller suggests', 'seller notes', 'meticulously crafted', 'designed with you in mind', 'say goodbye to',
        ],
        'fr' => [
            'haute qualité', 'de qualité supérieure', 'le look parfait', 'la tenue parfaite', 'coupe parfaite', 'qualité exceptionnelle', 'qualité irréprochable', 'parfait pour', 'parfaite pour',
            'idéal pour', 'idéale pour', 'sublimez', 'rehaussez', 'élevez votre', 'incontournable', 'ne cherchez plus', 'découvrez',
            'imaginez', 'imagine', 'il y a des produits que l\'on garde pour toujours', 'vous le cherchiez', 'exigent l\'excellence',
            'n\'attendez plus', 'must-have', 'le must', 'alliant style et confort', 'allie style et confort', 'élégance intemporelle',
            'sans compromis', 'faites tourner les têtes', 'le vendeur', 'la vendeuse', 'pensé pour vous', 'conçu pour durer', 'un incontournable',
        ],
        'ar' => [
            'عالي الجودة', 'عالية الجودة', 'جودة عالية', 'جودة ممتازة', 'جودة لا مثيل لها', 'مثالي', 'مثالية', 'ارتقِ',
            'لا تبحث بعد الآن', 'لا تبحث أكثر', 'اكتشف', 'اكتشفي', 'تخيّل', 'تخيل', 'لا تفوّت', 'لا تفوت', 'لا مثيل له', 'أناقة خالدة',
            'صُمّم خصيصًا لك', 'يجمع بين الأناقة والراحة', 'البائع',
        ],
    ];

    /**
     * Claims that need proof. Allowed only when the same words are in the product data
     * (e.g. the seller wrote "garantie 2 ans"); otherwise the repair pass rewrites them.
     */
    public const CLAIMS = [
        'en' => ['insulat', 'windproof', 'wind-proof', 'repels', 'thick leather', 'compact', 'lightweight', 'space-saving', 'genuine leather', 'real leather', 'waterproof', 'water-resistant', 'breathable', 'renowned', 'well-known brand', 'trusted brand', 'reputable', 'best-selling', 'bestseller', 'hidden fees', 'limited stock', 'only a few left', 'selling fast', 'while stocks last',
                 'warranty', 'guarantee', 'guaranteed', 'free delivery', 'free shipping', 'fast delivery', 'delivered in', 'certified', 'award'],
        'fr' => ['isolant', 'coupe-vent', 'déperlant', 'repousse l', 'cuir épais', 'compact', 'peu encombrant', 'gain de place', 'cuir véritable', 'véritable cuir', 'imperméable', 'respirant', 'résistant à', 'reconnue pour', 'reconnu pour', 'réputée', 'réputé', 'célèbre marque', 'best-seller', 'frais cachés', 'stock limité', 'quantités limitées', 'dans la limite des stocks', 'part vite', 'garantie', 'garanti ',
                 'livraison gratuite', 'livraison rapide', 'livré en', 'livrée en', 'certifié', 'certifiée', 'primé'],
        'ar' => ['يضمن لك', 'تضمن لك', 'عزلا', 'عزل فعال', 'تصمد أمام', 'يصمد أمام', 'مقاوم', 'مقاومة', 'عازل', 'عازلة', 'يصد', 'تصد', 'الجلد السميك', 'جلد سميك', 'صغير الحجم', 'خفيف الوزن', 'جلد طبيعي', 'جلد أصلي', 'مقاوم للماء', 'مقاومة للماء', 'يحميك من المطر', 'حماية من المطر', 'مشهورة', 'علامة موثوقة', 'الأكثر مبيعًا', 'كمية محدودة', 'الكمية محدودة', 'الأكثر مبيعا', 'ضمان', 'مضمون', 'توصيل مجاني', 'توصيل سريع', 'شحن مجاني', 'معتمد', 'حائز على'],
    ];

    private const LENGTHS = [
        'short'  => ['intro' => '1 sentence', 'bullets' => 3, 'words' => '50-80'],
        'medium' => ['intro' => '2 sentences', 'bullets' => 4, 'words' => '90-140'],
        'long'   => ['intro' => '2-3 sentences', 'bullets' => 5, 'words' => '150-210'],
    ];

    private const MOMENTS = [
        'all_season'     => 'all year round',
        'summer'         => 'summer (beach, heat, holidays, evenings outside)',
        'winter'         => 'winter (cold mornings and evenings, rain, layering up)',
        'ramadan'        => 'Ramadan (ftour and shour, evenings with family, preparing the house)',
        'aid'            => 'Aïd (new outfits, family visits, gifts, festive table)',
        'aid_fitr'       => 'Aïd el-Fitr (new outfits, family visits, sweets, gifts)',
        'aid_adha'       => 'Aïd el-Adha (family gathering, kitchen and grilling, hosting)',
        'back_to_school' => 'back to school (la rentrée: school bags, routines, organisation)',
        'wedding_season' => 'wedding season (outfits, gifts, outdoor evening celebrations)',
        'new_year'       => 'the end-of-year holidays (gifts, evenings out)',
        'winter_sales'   => 'the winter sales (soldes d\'hiver)',
        'summer_sales'   => 'the summer sales (soldes d\'été)',
    ];

    /**
     * @param  array $brief   product facts
     * @param  array $options tone, length, languages[], count, extras(bool)
     * @return array{system: string, user: string, facts: string, plan: array}
     */
    public function build(array $brief, array $options): array
    {
        $tone      = $options['tone'];
        $length    = self::LENGTHS[$options['length']];
        $languages = $options['languages'];
        $count     = $options['count'];
        $extras    = (bool) $options['extras'];
        $hasPromo  = !empty($brief['promo']);

        $hasOptions = !empty($brief['variants']) || !empty($brief['option_groups']) || ($brief['pack'] ?? null) !== null;
        $plan       = $this->variantPlan($languages, $count, $tone, $hasPromo, $hasOptions);
        $facts = $this->facts($brief, $languages[0]);

        $toneDef  = self::TONES[$tone];
        $examples = [];
        foreach (array_unique($languages) as $lang) {
            $examples[] = '[' . strtoupper($lang) . '] ' . $toneDef['example'][$lang];
        }
        $exampleNote = $toneDef['example_note'] ?? '';

        $banned = [];
        foreach (array_unique(array_merge($languages, ['en'])) as $lang) {
            $banned = array_merge($banned, self::BANNED[$lang]);
        }
        $bannedList = '"' . implode('", "', array_unique($banned)) . '"';

        $langRules = [];
        foreach (array_unique($languages) as $lang) {
            $langRules[] = '- ' . strtoupper($lang) . ': ' . self::LANGUAGES[$lang];
        }

        $system = <<<EOT
You write product descriptions for ChooseTounsi, a Tunisian marketplace of local sellers. Your copy must help the buyer decide, not decorate.

FACTS
- PRODUCT DATA is the only source of facts. Never invent materials, dimensions, capacities, technical specs, certifications, origin, "handmade", warranty, delivery times or conditions, stock levels, reviews, awards, brand reputation or popularity.
- Only mention colours, sizes or other options that are listed in PRODUCT DATA.
- Do not add qualities to a material that the data does not state (genuine, natural, soft, thick, waterproof, insulating, windproof, breathable, durable…).
- If a detail is missing, write around it (use, feel, occasion) instead of guessing. Never mention photos.
- Write brand names, model names and the store name exactly as given (never "correct" their spelling).
- You write to the buyer. Never mention the seller, the notes, the draft, "the data" or these instructions (no "as the seller says").

COPYWRITING
- Benefits before features: each bullet starts with what the buyer gets, then the concrete detail from PRODUCT DATA that proves it.
- Say who it is for and when to use it. Mention at most one occasion or season per variant, and only when it genuinely fits the product (the seller's occasions first, then the upcoming moments listed).
- Use the keywords to include naturally, each at least once across intro, bullets and closing.
- Mention the store name at most once, only in the closing, and only if it sounds natural.
- Specific beats grand: no empty superlatives, no claims you cannot back with PRODUCT DATA.

TONE: {$tone}
{$toneDef['rules']}
Style sample for a different product {$exampleNote}. Imitate the voice only, never reuse its words, facts or structure:
{$this->lines($examples)}

LANGUAGE
{$this->lines($langRules)}

BANNED WORDS AND PHRASES (in any language, including close variants and translations):
{$bannedList}

FORMAT
- Plain text only: no markdown, no asterisks, no "#", no HTML, no emojis (hashtags are allowed only in "social").
- Bullets are plain strings, without a leading dash, dot, number or symbol.
- Respond with one JSON object only, no text around it.
EOT;

        $variantLines = [];
        foreach ($plan as $i => $v) {
            $n = $i + 1;
            $variantLines[] = "Variant {$n}: language {$v['language']}. Opening — " . self::HOOKS[$v['hook']] . ' Closing — ' . self::CLOSINGS[$v['closing']];
        }

        $extrasSchema = $extras
            ? ",\n      \"seo_title\": \"<search title, max 65 characters: product type + key attribute + store or category, no clickbait>\",\n      \"tags\": [\"<5 to 8 short search keywords/tags a Tunisian buyer would type, in the variant's language, lowercase, no #>\"],\n      \"social\": \"<a social media caption, max 220 characters, may end with 2-3 hashtags>\""
            : '';

        $calendar = $this->calendarContext();

        $user = <<<EOT
PRODUCT DATA
{$facts}

CONTEXT (not product facts)
{$calendar}

WRITE {$count} VARIANT(S). Length per variant: intro {$length['intro']}, up to {$length['bullets']} bullets (max 20 words each), one closing sentence; about {$length['words']} words in total.
Bullets: every bullet must rest on a fact from PRODUCT DATA (an attribute, an option, the pack, the price or offer, the seller notes or draft). One bullet may instead describe a use or an occasion, without claiming any property. When there are not enough facts, write fewer bullets (minimum 2) — never pad with generic or invented claims (speed, energy saving, lightness, durability, easy cleaning…). Start each bullet with a different word. The price alone is not a bullet unless there is a promotion.
Each variant must feel written by a different copywriter: different first words, different angle, different bullet order. No two variants may share a sentence.
{$this->lines($variantLines)}

JSON SCHEMA
{
  "variants": [
    {
      "language": "<fr|ar|en>",
      "intro": "<the opening, following that variant's opening strategy>",
      "bullets": ["<benefit, then the concrete detail>"],
      "closing": "<one sentence with a call to action>",
      "short": "<a standalone summary for mobile product cards, max 150 characters>"{$extrasSchema}
    }
  ]
}
EOT;

        return [
            'system' => $system,
            'user'   => $user,
            'facts'  => $facts,
            'plan'   => $plan,
            'schema' => $this->schema($count, $extras, $languages),
        ];
    }

    /** Strict JSON schema for Groq structured outputs: exact variant count and fields. */
    public function schema(int $count, bool $extras, array $languages): array
    {
        $props = [
            'language' => ['type' => 'string', 'enum' => array_values(array_unique($languages))],
            'intro'    => ['type' => 'string'],
            'bullets'  => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2, 'maxItems' => 6],
            'closing'  => ['type' => 'string'],
            'short'    => ['type' => 'string'],
        ];
        if ($extras) {
            $props['seo_title'] = ['type' => 'string'];
            $props['tags']      = ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 5, 'maxItems' => 8];
            $props['social']    = ['type' => 'string'];
        }

        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['variants'],
            'properties'           => [
                'variants' => [
                    'type'     => 'array',
                    'minItems' => $count,
                    'maxItems' => $count,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array_keys($props),
                        'properties'           => $props,
                    ],
                ],
            ],
        ];
    }

    /**
     * One entry per variant: language, opening strategy and closing style, all distinct.
     */
    public function variantPlan(array $languages, int $count, string $tone, bool $hasPromo, bool $hasOptions = true): array
    {
        $hooks = array_keys(self::HOOKS);
        $hooks = array_values(array_diff($hooks, ['offer'], self::TONES[$tone]['avoid_hooks'] ?? []));
        shuffle($hooks);
        if ($hasPromo && $tone === 'promo') {
            array_unshift($hooks, 'offer');   // promo tone with a real offer: lead variant 1 with it
        }

        $closings = array_keys(self::CLOSINGS);
        if (!$hasOptions) $closings = array_values(array_diff($closings, ['choice']));   // nothing to choose from
        shuffle($closings);

        $plan = [];
        for ($i = 0; $i < $count; $i++) {
            $plan[] = [
                'language' => $languages[$i] ?? $languages[0],
                'hook'     => $hooks[$i % count($hooks)],
                'closing'  => $closings[$i % count($closings)],
            ];
        }
        return $plan;
    }

    /** The fact sheet sent to the model — also used by the cleaner to spot invented specs. */
    public function facts(array $brief, string $lang): string
    {
        $lines   = [];
        $lines[] = '- Product name: ' . $brief['name'];
        if (!empty($brief['store']))    $lines[] = '- Store: ' . $brief['store'];
        $category = trim(($brief['category'] ?? '') . (!empty($brief['subcategory']) ? ' > ' . $brief['subcategory'] : ''));
        if ($category !== '')           $lines[] = '- Category: ' . $category;

        if (!empty($brief['price'])) {
            $line = '- Price: ' . $this->money((float) $brief['price'], $lang);
            if (!empty($brief['promo'])) {
                $p     = $brief['promo'];
                $line .= sprintf(
                    ' | ON PROMOTION%s: -%d%%, now %s%s',
                    !empty($p['flash']) ? ' (flash sale)' : '',
                    (int) $p['percent'],
                    $this->money((float) $p['final'], $lang),
                    !empty($p['ends_at']) ? ', until ' . $p['ends_at'] : ''
                );
            }
            $lines[] = $line;
        }

        if (!empty($brief['attributes'])) {
            $pairs = [];
            foreach ($brief['attributes'] as $label => $value) $pairs[] = "{$label}: {$value}";
            $lines[] = '- Attributes: ' . implode('; ', $pairs);
        }
        if (!empty($brief['option_groups'])) {
            $groups = [];
            foreach ($brief['option_groups'] as $label => $values) {
                $groups[] = "{$label} (" . count($values) . '): ' . implode(', ', $values);
            }
            $lines[] = '- Options by type: ' . implode(' | ', $groups);
        }
        if (!empty($brief['variants'])) {
            $lines[] = '- Available combinations (pair options only as listed): ' . implode(', ', $brief['variants']);
        }
        if (($brief['pack'] ?? null) !== null) {
            $pack    = $brief['pack'];
            $lines[] = '- Sold as a pack' . (!empty($pack['quantity']) ? ' of ' . $pack['quantity'] : '')
                . (!empty($pack['contents']) ? ': ' . $pack['contents'] : '');
        }
        if (!empty($brief['stock']) && $brief['stock'] <= 10) {
            $lines[] = '- Stock left: ' . $brief['stock'];
        }

        $occasions = array_values(array_diff($brief['occasions'] ?? [], ['all_season']));
        if ($occasions) {
            $lines[] = '- Occasions chosen by the seller: ' . implode(', ', array_map(fn($o) => self::MOMENTS[$o] ?? $o, $occasions));
        }
        if (!empty($brief['notes'])) $lines[] = '- Seller notes (facts you may use): "' . $brief['notes'] . '"';
        if (!empty($brief['draft'])) $lines[] = '- Seller\'s own draft (facts you may use, rewrite freely): "' . $brief['draft'] . '"';
        if (!empty($brief['keywords'])) $lines[] = '- Keywords to include: ' . implode(', ', $brief['keywords']);

        if (!empty($brief['brand_voice']['voice'])) {
            $lines[] = '- Store voice (follow it unless it contradicts the tone): "' . $brief['brand_voice']['voice'] . '"';
        }
        if (!empty($brief['brand_voice']['keywords'])) {
            $lines[] = '- Store keywords (use when relevant): ' . implode(', ', $brief['brand_voice']['keywords']);
        }

        return implode("\n", $lines);
    }

    /** Date and upcoming Tunisian moments — context only, not product facts. */
    public function calendarContext(): string
    {
        $today   = CarbonImmutable::now(config('ai_descriptions.timezone'));
        $moments = $this->upcomingMoments($today);
        return 'Today: ' . $today->format('j F Y') . '. Upcoming moments in Tunisia: '
            . ($moments ? implode('; ', $moments) : 'none in particular') . '.';
    }

    /** Moments that are running now or start within the look-ahead window. */
    public function upcomingMoments(CarbonImmutable $today): array
    {
        $cal    = config('ai_descriptions.calendar');
        $window = $today->addDays((int) $cal['lookahead_days']);
        $found  = [];

        $consider = function (string $key, CarbonImmutable $from, CarbonImmutable $to) use ($today, $window, &$found) {
            if ($to->lt($today) || $from->gt($window)) return;
            $when = $from->lte($today) ? 'now' : 'from ' . $from->format('j M');
            $found[$from->format('Ymd') . $key] = (self::MOMENTS[$key] ?? $key) . " ({$when})";
        };

        foreach ($cal['dated'] as $d) {
            $consider($d['key'], CarbonImmutable::parse($d['from'], $today->getTimezone()), CarbonImmutable::parse($d['to'], $today->getTimezone())->endOfDay());
        }
        foreach ($cal['yearly'] as $y) {
            foreach ([$today->year - 1, $today->year, $today->year + 1] as $year) {
                $from = CarbonImmutable::parse("{$year}-{$y['from']}", $today->getTimezone());
                $to   = CarbonImmutable::parse("{$year}-{$y['to']}", $today->getTimezone())->endOfDay();
                if ($to->lt($from)) $to = $to->addYear();   // ranges across new year (winter)
                $consider($y['key'], $from, $to);
            }
        }

        ksort($found);
        return array_values($found);
    }

    public function money(float $amount, string $lang): string
    {
        $decimals = fmod($amount, 1.0) == 0.0 ? 0 : 3;
        $number   = $lang === 'en'
            ? number_format($amount, $decimals, '.', ',')
            : number_format($amount, $decimals, ',', ' ');
        return $lang === 'ar' ? "{$number} د.ت" : "{$number} DT";
    }

    private function lines(array $lines): string
    {
        return implode("\n", $lines);
    }
}
