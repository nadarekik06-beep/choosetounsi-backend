<?php

namespace App\Support;

use App\Models\AttributeOption;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * The "Multicolore" color: one option of the color attribute, for items that carry
 * several colors (e.g. a black & white sneaker). One per product.
 *
 * - The DB keeps a plain hex (HEX) in attribute_options.color_hex; only the model
 *   accessor serves the GRADIENT, so every swatch (`background: color_hex`) renders it.
 * - The seller's optional description ("Noir / Blanc") is stored per product in
 *   product_attribute_values, under the hidden LABEL_ATTRIBUTE. Shown instead of
 *   "Multicolore" wherever the variant is displayed.
 */
final class Multicolor
{
    /** Base (English) value: the stable key of the option, with the color attribute. */
    public const VALUE     = 'Multicolor';
    public const VALUE_FR  = 'Multicolore';
    public const VALUE_AR  = 'متعدد الألوان';
    public const HEX       = '#9CA3AF';
    public const GRADIENT  = 'linear-gradient(135deg, #ef4444 0%, #f59e0b 25%, #22c55e 50%, #3b82f6 75%, #a855f7 100%)';

    public const LABEL_ATTRIBUTE = 'multicolor-label';
    public const LABEL_MAX       = 40;

    /** Per app instance (so tests with a fresh DB never see a stale id). */
    private static function cache(): \ArrayObject
    {
        $key = 'multicolor.cache';
        if (!app()->bound($key)) app()->instance($key, new \ArrayObject());
        return app($key);
    }

    public static function optionId(): ?int
    {
        $c = self::cache();
        if (!$c->offsetExists('option')) {
            $id = DB::table('attribute_options as o')
                ->join('attributes as a', 'a.id', '=', 'o.attribute_id')
                ->where('a.slug', 'color')->where('o.value', self::VALUE)
                ->value('o.id');
            $c['option'] = $id ? (int) $id : null;
        }
        return $c['option'];
    }

    public static function labelAttributeId(): ?int
    {
        $c = self::cache();
        if (!$c->offsetExists('label_attr')) {
            $id = DB::table('attributes')->where('slug', self::LABEL_ATTRIBUTE)->value('id');
            $c['label_attr'] = $id ? (int) $id : null;
        }
        return $c['label_attr'];
    }

    public static function flush(): void
    {
        self::cache()->exchangeArray([]);
    }

    public static function isOption($option): bool
    {
        $id = $option instanceof AttributeOption ? $option->getKey() : $option;
        $mc = self::optionId();
        return $mc !== null && $id !== null && (int) $id === $mc;
    }

    /** True when one of the option id lists contains the Multicolore option. */
    public static function inOptionIds(iterable $ids): bool
    {
        $mc = self::optionId();
        if ($mc === null) return false;
        foreach ($ids as $id) if ((int) $id === $mc) return true;
        return false;
    }

    /** The product's description, or null (uses loaded attributeValues when present). */
    public static function labelFor($product): ?string
    {
        $attrId = self::labelAttributeId();
        $pid    = $product instanceof Product ? $product->getKey() : $product;
        if (!$attrId || !$pid) return null;

        $value = $product instanceof Product && $product->relationLoaded('attributeValues')
            ? optional($product->attributeValues->firstWhere('attribute_id', $attrId))->value
            : ProductAttributeValue::where('product_id', $pid)->where('attribute_id', $attrId)->value('value');

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** Saves (or clears, with null / '') the product's description. */
    public static function saveLabel(Product $product, ?string $label): void
    {
        $attrId = self::labelAttributeId();
        if (!$attrId) return;

        $label = trim((string) $label);
        if ($label === '') {
            ProductAttributeValue::where('product_id', $product->id)->where('attribute_id', $attrId)->delete();
            return;
        }
        ProductAttributeValue::updateOrCreate(
            ['product_id' => $product->id, 'attribute_id' => $attrId],
            ['value' => mb_substr($label, 0, self::LABEL_MAX)]
        );
    }

    /**
     * Shows the description instead of "Multicolore" on the product's loaded variant
     * options (in memory only). $withName: "Multicolore · Noir / Blanc" (admin views).
     */
    public static function applyToProduct(Product $product, bool $withName = false): void
    {
        if (self::optionId() === null || !$product->relationLoaded('variants')) return;
        $label = self::labelFor($product);
        if ($label === null) return;

        foreach ($product->variants as $variant) {
            if ($variant->relationLoaded('attributeOptions')) {
                self::applyToOptions($variant->attributeOptions, $label, $withName);
            }
        }
    }

    /** Same, for one variant (cart, favorites…): reads the description only when needed. */
    public static function applyToVariant(ProductVariant $variant, bool $withName = false): void
    {
        if (!$variant->relationLoaded('attributeOptions')) return;
        if (!$variant->attributeOptions->contains(fn($o) => self::isOption($o))) return;
        $product = $variant->relationLoaded('product') ? $variant->product : $variant->product_id;
        self::applyToOptions($variant->attributeOptions, self::labelFor($product), $withName);
    }

    public static function applyToOptions(iterable $options, ?string $label, bool $withName = false): void
    {
        if ($label === null || $label === '') return;
        foreach ($options as $opt) {
            if ($opt instanceof AttributeOption && self::isOption($opt)) {
                $opt->displayLabel = $withName ? $opt->translatedValue() . ' · ' . $label : $label;
            }
        }
    }
}
