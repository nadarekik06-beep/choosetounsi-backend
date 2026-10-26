<?php

/**
 * Attributes of NEW non-fashion subcategories created by `php artisan categories:import`.
 * (New Mode & Vêtements subcategories copy T-shirt / Sneakers / Sac à main / Montre by column.)
 *
 * Only real matches are copied; anything not listed gets `default` (brand only), never a
 * mismatched setup. Keys are CSV "<category_slug>/<subcategory_slug>".
 *   copy        → existing "<category>/<subcategory>" whose attribute setup is copied (gender removed)
 *   attributes  → explicit attribute slugs (not variant, not required)
 */
return [
    'default' => ['brand'],

    'copy' => [
        // Électronique
        'electronics-tech/feature-phones'        => 'electronics-tech/smartphone',
        'electronics-tech/desktop-computers'     => 'electronics-tech/laptop',
        'electronics-tech/soundbars-home-cinema' => 'electronics-tech/bluetooth-speaker',

        // Alimentation (weight, origin, organic, shelf life)
        'food-grocery/bsissa-traditional-products' => 'food-grocery/olive-oil',
        'food-grocery/tunisian-pastries'           => 'food-grocery/olive-oil',
        'food-grocery/pasta-couscous-grains'       => 'food-grocery/canned-goods',
        'food-grocery/jams-spreads'                => 'food-grocery/canned-goods',
        'food-grocery/nuts-dried-fruits'           => 'food-grocery/canned-goods',
        'food-grocery/chocolate-sweets'            => 'food-grocery/canned-goods',
        'food-grocery/biscuits-snacks'             => 'food-grocery/canned-goods',
        'food-grocery/dairy-cheese'                => 'food-grocery/canned-goods',
        'food-grocery/coffee-tea'                  => 'food-grocery/coffee',
        'food-grocery/juices-syrups'               => 'food-grocery/coffee',
        'food-grocery/water-soft-drinks'           => 'food-grocery/coffee',
        'food-grocery/gluten-free'                 => 'food-grocery/organic-products',

        // Beauté
        'beauty-care/face-makeup' => 'beauty-personal-care/foundation',
        'beauty-care/eye-makeup'  => 'beauty-personal-care/mascara',
        'beauty-care/skincare'    => 'beauty-personal-care/moisturiser',
        'beauty-care/body-care'   => 'beauty-personal-care/moisturiser',
        'beauty-care/hair-care'   => 'beauty-personal-care/shampoo',

        // Santé
        'health-wellness/supplements'     => 'health-wellness/vitamins',
        'health-wellness/sports-nutrition' => 'health-wellness/vitamins',
        'health-wellness/herbal-products' => 'health-wellness/vitamins',

        // Enfants
        'kids-baby/girls-clothing'      => 'kids-baby/baby-clothes',
        'kids-baby/boys-clothing'       => 'kids-baby/baby-clothes',
        'kids-baby/costumes'            => 'kids-baby/baby-clothes',
        'kids-baby/baby-toys'           => 'kids-baby/plush-toy',
        'kids-baby/educational-toys'    => 'kids-baby/plush-toy',
        'kids-baby/board-games-puzzles' => 'kids-baby/plush-toy',
        'kids-baby/dolls'               => 'kids-baby/plush-toy',
        'kids-baby/outdoor-play'        => 'kids-baby/plush-toy',

        // Livres & Papeterie
        'books-stationery/arabic-books'       => 'books-stationery/novel',
        'books-stationery/french-books'       => 'books-stationery/novel',
        'books-stationery/english-books'      => 'books-stationery/novel',
        'books-stationery/school-study-books' => 'books-stationery/novel',
        'books-stationery/children-s-books'   => 'books-stationery/novel',
        'books-stationery/religious-books'    => 'books-stationery/novel',
        'books-stationery/self-help'          => 'books-stationery/novel',
        'books-stationery/cookbooks'          => 'books-stationery/novel',
        'books-stationery/school-supplies'    => 'books-stationery/notebook',
        'books-stationery/pens-writing'       => 'books-stationery/notebook',
        'books-stationery/office-supplies'    => 'books-stationery/notebook',
        'books-stationery/art-supplies'       => 'books-stationery/notebook',

        // Auto
        'auto-moto/interior-accessories' => 'automotive/car-accessory',
        'auto-moto/exterior-accessories' => 'automotive/car-accessory',
    ],

    'attributes' => [
        'electronics-tech/monitors' => ['brand', 'screen-size'],
        'electronics-tech/tvs'      => ['brand', 'screen-size'],
    ],
];
