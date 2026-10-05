<?php

namespace App\Services\Search;

use App\Models\Product;

/**
 * Product text the search bar's MySQL index (SearchIndexer) adds to names and description:
 * category names in every language, attribute values and variant options.
 */
class ProductDocument
{
    /** Relations categoryNames() and attributeValues() read: eager-load them for a batch. */
    const RELATIONS = ['category', 'subcategory', 'attributeValues.attribute', 'activeVariants'];

    /** @return string[] category + subcategory names in every language */
    public function categoryNames(Product $product): array
    {
        $names = [];
        foreach ([$product->category, $product->subcategory] as $c) {
            if ($c) {
                foreach (['name', 'name_fr', 'name_ar'] as $col) {
                    $names[] = $c->getRawOriginal($col);
                }
            }
        }
        return array_unique(array_filter($names));
    }

    /** @return string[] attribute values (brand, material, gender…) and active variant options (colors, sizes) */
    public function attributeValues(Product $product): array
    {
        $values = [];
        foreach ($product->attributeValues as $pav) {
            foreach ((array) (json_decode((string) $pav->value, true) ?? $pav->value) as $v) {
                if (is_scalar($v) && trim((string) $v) !== '' && mb_strlen((string) $v) <= 60) {
                    $values[] = (string) $v;
                }
            }
        }
        foreach ($product->activeVariants as $variant) {
            foreach ($variant->attributeOptions as $opt) {
                array_push($values, (string) $opt->value, (string) $opt->value_fr, (string) $opt->value_ar);
            }
        }
        return array_values(array_unique(array_filter($values)));
    }
}
