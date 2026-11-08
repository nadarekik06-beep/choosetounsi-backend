<?php

/*
 * Product page recommendations: "Articles similaires" and "Complétez le look".
 * Used by App\Services\Recommendation\RelatedProducts. Homepage feed settings are in recommendations.php.
 *
 * Subcategories are matched by slug, never by id (ids differ between databases).
 * A trailing "-2", "-3"… is ignored, so a duplicate like "jackets-2" counts as "jackets".
 */

return [

    // Seconds a product's result list stays cached (cards and prices are always rebuilt fresh).
    'cache_seconds' => 600,

    // Price window for "similaires": ±50 % first, then ±100 %, then any price in the same family.
    'price_bands' => [0.5, 1.0],

    // At most this many products per seller, unless fewer than `min_results` are left.
    'max_per_seller' => 2,
    'min_results'    => 4,

    /*
     * Families of subcategories, per category slug. "Similaires" widens from the same
     * subcategory to the same family and never further. A subcategory in no family
     * (or a category not listed) only matches its own subcategory.
     */
    'families' => [
        'fashion-clothing' => [
            'outerwear'   => ['jackets', 'denim-jacket', 'coats-puffers', 'blazers-suits'],
            'tops'        => ['t-shirt', 'shirt', 'tops-blouses', 'polo-shirts', 'sweatshirt', 'sweaters-cardigans'],
            'bottoms'     => ['jeans', 'trousers', 'shorts', 'skirts'],
            'dresses'     => ['dress', 'jumpsuits', 'two-piece-sets'],
            'special'     => ['plus-size', 'modest-wear', 'maternity-wear'],
            'sportswear'  => ['sportswear'],
            'shoes'       => ['high-heels', 'sneakers', 'sandals', 'suede-clogs', 'eva-clogs', 'ballet-flats', 'boots',
                              'loafers', 'formal-shoes', 'mules-clogs', 'slides-flip-flops', 'slippers'],
            'bags'        => ['handbag', 'backpack', 'crossbody-bags', 'clutches', 'tote-bags', 'wallets', 'travel-bags-suitcases'],
            'accessories' => ['watch', 'scarf', 'sunglasses', 'belts', 'hijabs', 'caps-hats', 'beanies-gloves',
                              'ties-bow-ties', 'hair-accessories'],
            'jewelry'     => ['necklaces', 'bracelets', 'earrings', 'rings', 'silver-jewelry', 'gold-jewelry', 'jewelry-sets'],
            'underwear'   => ['bras', 'panties', 'men-s-underwear', 'socks', 'tights'],
            'swimwear'    => ['swimwear', 'burkinis'],
            'loungewear'  => ['pyjama-set', 'nightdresses-robes'],
            'traditional' => ['jebbas', 'kaftans', 'blouzas', 'traditional-keswas', 'sefsaris', 'balghas',
                              'kachabias-burnous', 'chechias'],
        ],
        'electronics-tech' => [
            'phones'        => ['smartphone', 'feature-phones'],
            'computers'     => ['tablet', 'laptop', 'desktop-computers'],
            'audio'         => ['earphones', 'headphones', 'bluetooth-speaker', 'soundbars-home-cinema'],
            'phone-gear'    => ['screen-protectors', 'power-banks', 'phone-case', 'charger'],
            'computer-gear' => ['monitors', 'keyboards-mice', 'printers-ink', 'pc-components', 'laptop-bags-accessories', 'networking-wi-fi'],
            'storage'       => ['usb-drive', 'storage-drives-usb-keys'],
            'tv-video'      => ['tv', 'tvs', 'receivers-tv-boxes', 'projectors'],
            'gaming'        => ['gaming-console', 'video-games', 'gaming-accessories'],
            'cameras'       => ['cameras', 'security-cameras', 'drones'],
        ],
        'beauty-personal-care' => [
            'makeup'   => ['face-makeup', 'eye-makeup', 'lipstick', 'foundation', 'mascara', 'nails', 'makeup-tools'],
            'skincare' => ['moisturiser', 'serum', 'face-mask', 'cleansing-gel', 'skincare', 'argan-oil', 'natural-beauty-henna-tfal-argan'],
            'haircare' => ['shampoo', 'hair-care', 'hair-mask', 'hair-color', 'hair-styling-tools'],
            'body'     => ['body-care', 'hammam-bath', 'deodorants', 'shaving-beard', 'hair-removal'],
        ],
        'health-wellness' => [
            'supplements' => ['vitamins', 'protein-powder', 'supplements', 'sports-nutrition', 'herbal-products', 'herbal-tea'],
            'medical'     => ['medical-device', 'blood-pressure-monitors-thermometers', 'medical-equipment', 'orthopedics', 'first-aid'],
            'intimate'    => ['feminine-care', 'intimate-hygiene'],
        ],
        'home-living' => [
            'furniture' => ['sofa', 'bed-frame', 'dining-table', 'living-room-furniture', 'bedroom-furniture', 'office-furniture', 'storage-shelves'],
            'decor'     => ['mirrors', 'rug', 'curtains', 'vases-artificial-plants', 'clocks', 'wall-art', 'candle', 'cushions-throws', 'lighting'],
            'bedding'   => ['bed-sheets', 'bed-linen', 'blankets-duvets', 'mattresses-pillows'],
            'bath'      => ['bath-towels', 'foutas', 'bathroom-accessories'],
            'kitchen'   => ['crockery-set', 'cookware', 'glassware-mugs', 'cutlery', 'kitchen-storage'],
        ],
        'home-appliances' => [
            'large'   => ['refrigerators', 'freezers', 'washing-machines', 'dishwashers', 'cookers-ovens'],
            'climate' => ['air-conditioners', 'heaters', 'water-heaters', 'fans', 'air-purifiers'],
            'kitchen' => ['microwaves', 'coffee-machines', 'blenders', 'food-processors', 'fryers-air-fryers', 'kettles', 'toasters-sandwich-makers'],
            'care'    => ['irons', 'vacuum-cleaners'],
        ],
        'books-stationery' => [
            'books'      => ['novel', 'comic-manga', 'arabic-books', 'french-books', 'english-books', 'school-textbook',
                             'school-study-books', 'children-s-books', 'religious-books', 'self-help', 'cookbooks'],
            'stationery' => ['notebook', 'pen-set', 'planner', 'school-supplies', 'pens-writing', 'office-supplies', 'art-supplies'],
        ],
        'kids-baby' => [
            'clothing'  => ['baby-clothes', 'girls-clothing', 'boys-clothing', 'costumes'],
            'toys'      => ['plush-toy', 'educational-game', 'toy-car', 'baby-toys', 'educational-toys', 'board-games-puzzles', 'dolls', 'outdoor-play'],
            'gear'      => ['stroller', 'strollers-car-seats', 'nursery'],
            'baby-care' => ['baby-bottle', 'diapers-wipes', 'baby-food', 'feeding-nursing', 'baby-bath-care'],
        ],
        'sports-outdoors' => [
            'shoes'    => ['running-shoes', 'sports-shoes'],
            'clothing' => ['football-kit', 'sports-t-shirt', 'tracksuit'],
            'fitness'  => ['yoga-mat', 'weights', 'weights-strength', 'cardio-equipment', 'yoga-pilates'],
            'ball'     => ['football', 'basketball-volleyball', 'tennis-padel'],
            'outdoor'  => ['camping-hiking', 'fishing', 'beach-gear'],
            'ride'     => ['bicycle', 'cycling', 'scooters-skates'],
        ],
        'food-grocery' => [
            'pantry' => ['olive-oil', 'harissa', 'spices', 'canned-goods', 'pasta-couscous-grains', 'bsissa-traditional-products'],
            'sweet'  => ['honey', 'dates', 'tunisian-pastries', 'jams-spreads', 'chocolate-sweets', 'biscuits-snacks', 'nuts-dried-fruits'],
            'drinks' => ['tea', 'coffee', 'coffee-tea', 'juices-syrups', 'water-soft-drinks'],
        ],
        'arts-crafts' => [
            'supplies' => ['acrylic-paint', 'canvas', 'embroidery-kit', 'knitting-yarn', 'haberdashery-fabrics', 'diy-craft-supplies'],
            'jewelry'  => ['handmade-jewelry', 'berber-jewelry'],
            'handmade' => ['pottery', 'rugs-mergoum', 'basketry-alfa', 'copperware', 'olive-wood', 'blown-glass', 'leather-crafts',
                           'wrought-iron-cages', 'mosaic', 'calligraphy-paintings', 'embroidery-weaving'],
        ],
    ],

    /*
     * "Complétez le look": family → complementary families, in display order.
     * Only categories listed here get the section; a family without an entry hides it.
     * The product's own subcategory is always excluded.
     */
    'complements' => [
        'fashion-clothing' => [
            'outerwear'   => ['bottoms', 'tops', 'shoes', 'accessories', 'bags'],
            'tops'        => ['bottoms', 'outerwear', 'shoes', 'accessories'],
            'bottoms'     => ['tops', 'outerwear', 'shoes', 'accessories'],
            'dresses'     => ['shoes', 'bags', 'jewelry', 'accessories', 'outerwear'],
            'special'     => ['shoes', 'bags', 'jewelry', 'accessories'],
            'sportswear'  => ['shoes', 'accessories', 'bags'],
            'shoes'       => ['bottoms', 'tops', 'dresses', 'bags', 'accessories'],
            'bags'        => ['dresses', 'shoes', 'jewelry', 'accessories', 'tops'],
            'accessories' => ['tops', 'outerwear', 'bags', 'shoes'],
            'jewelry'     => ['dresses', 'bags', 'accessories', 'traditional'],
            'swimwear'    => ['accessories', 'bags'],
            'traditional' => ['traditional', 'jewelry', 'shoes', 'bags'],
        ],
    ],
];
