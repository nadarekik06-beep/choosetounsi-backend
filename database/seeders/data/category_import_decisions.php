<?php

/**
 * Decisions for `php artisan categories:import` on items it will not decide alone
 * (similar names, same name in another category, duplicates inside the CSV).
 *
 *   'sub:<csv category>/<csv subcategory>'   => 'new' | 'match:<existing category>/<existing subcategory>'
 *   'category:<csv category>'                => 'new' | 'match:<existing category>'
 *   'column:<category>/<women|men|all>/<CSV column FR>' => 'new' | 'match:<existing column FR name>'
 *
 * 'match' = the existing one is kept as is (never renamed, re-slugged or moved; its products untouched)
 * and is placed in the CSV's menu column. 'new' = created as a separate subcategory / column.
 * 'new+place:<existing category>/<existing subcategory>' = created as new, and the existing (narrower)
 * one is shown in the same menu column (moved there if it sat in another column of that menu).
 *
 * '_remove_columns' lists menu columns to take out ("<category>/<women|men|all|*>/<column FR name>"):
 * only the menu placements go, never the subcategories or their products.
 */
return [
    // ── Subcategories: CSV row ≈ existing subcategory ─────────────────────
    'sub:fashion-clothing/sweatshirts-hoodies'      => 'match:fashion-clothing/sweatshirt',
    'sub:fashion-clothing/heels'                    => 'match:fashion-clothing/high-heels',
    'sub:fashion-clothing/trousers'                 => 'new',   // only ambiguous where a "SweatPants" subcategory exists
    'sub:fashion-clothing/scarves'                  => 'match:fashion-clothing/scarf',
    'sub:electronics-tech/chargers-cables'          => 'match:electronics-tech/charger',
    'sub:electronics-tech/storage-drives-usb-keys'  => 'new+place:electronics-tech/usb-drive',
    'sub:electronics-tech/headphones-earbuds'       => 'match:electronics-tech/earphones',
    'sub:electronics-tech/receivers-tv-boxes'       => 'new',
    'sub:electronics-tech/game-consoles'            => 'match:electronics-tech/gaming-console',
    'sub:home-decor/wall-art-frames'                => 'match:home-living/wall-art',
    'sub:home-decor/candles-home-fragrance'         => 'match:home-living/candle',
    'sub:home-decor/dinnerware'                     => 'match:home-living/crockery-set',
    'sub:food-grocery/spices-harissa'               => 'match:food-grocery/spices',
    'sub:food-grocery/coffee-tea'                   => 'new+place:food-grocery/coffee',
    'sub:beauty-care/face-makeup'                   => 'new+place:beauty-personal-care/foundation',
    'sub:beauty-care/lip-makeup'                    => 'match:beauty-personal-care/lipstick',
    'sub:sports-outdoors/sportswear'                => 'match:fashion-clothing/sportswear',
    'sub:sports-outdoors/sports-shoes'              => 'new',
    'sub:sports-outdoors/football-jerseys'          => 'match:sports-outdoors/football-kit',   // Maillots de supporters = Tenue de football
    'sub:sports-outdoors/weights-strength'          => 'new+place:sports-outdoors/weights',
    'sub:sports-outdoors/football'                  => 'new',
    'sub:sports-outdoors/swimming'                  => 'match:sports-outdoors/swimming-gear',
    'sub:sports-outdoors/running'                   => 'new',
    'sub:kids-baby/feeding-nursing'                 => 'new+place:kids-baby/baby-bottle',
    'sub:kids-baby/strollers-car-seats'             => 'new+place:kids-baby/stroller',
    'sub:kids-baby/toy-cars-tracks'                 => 'match:kids-baby/toy-car',
    'sub:books-stationery/planners-calendars'       => 'match:books-stationery/planner',
    'sub:arts-crafts/pottery-ceramics'              => 'match:arts-crafts/pottery',
    'sub:auto-moto/helmets-riding-gear'             => 'match:automotive/motorcycle-gear',

    // ── Menu columns: CSV column ≈ existing column ────────────────────────
    'column:fashion-clothing/women/Accessoires'           => 'match:Accessoires & sacs',
    'column:fashion-clothing/men/Accessoires'             => 'match:Accessoires & sacs',
    'column:electronics-tech/all/Audio'                   => 'match:Audio & vidéo',
    'column:home-living/all/Cuisine & Table'              => 'match:Cuisine & art de la table',
    'column:beauty-personal-care/all/Soins'               => 'new',
    'column:beauty-personal-care/all/Parfums & Hygiène'   => 'match:Parfums',
    'column:sports-outdoors/all/Sports'                   => 'new',
    'column:arts-crafts/all/Artisanat tunisien'           => 'match:Artisanat',
    'column:automotive/all/Auto'                          => 'match:Accessoires auto',
    'column:automotive/all/Moto & Mobilité'               => 'match:Moto',

    // ── Old Mode & Vêtements columns: their items live in Beauté and Sport ─
    '_remove_columns' => [
        'fashion-clothing/*/Cosmétiques',
        'fashion-clothing/*/Sport & loisirs',
    ],
];
