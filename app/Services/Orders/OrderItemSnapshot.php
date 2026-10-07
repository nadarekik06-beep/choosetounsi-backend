<?php

namespace App\Services\Orders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductImages;
use Illuminate\Support\Facades\Storage;

/**
 * What the buyer bought, frozen on the order line at checkout so later product
 * edits can't change it: the variant's attributes and a copy of its image.
 *
 * The order line is the source of truth for every screen that shows a bought
 * item (orders, invoices, complaints, refunds, emails). Image display order:
 *   order_items.image_url snapshot → live variant color image → product main image → null (placeholder)
 * Another color's image is never used: the "main image" fallback only takes a
 * picture that belongs to no color (or to the bought one).
 *
 * The image is copied to order-snapshots/ (named by content hash, so the same
 * picture is stored once): sellers replacing or deleting product photos delete
 * the product file, never the copy.
 */
class OrderItemSnapshot
{
    public const DIR = 'order-snapshots';

    // order_items.image_source
    public const SRC_VARIANT  = 'variant';   // the bought variant's own image
    public const SRC_PRODUCT  = 'product';   // simple product: its main image is exact
    public const SRC_LABEL    = 'label';     // backfill: variant gone, color matched from variant_label
    public const SRC_FALLBACK = 'fallback';  // variant bought but its image unknown → neutral product image
    public const SRC_NONE     = 'none';      // nothing usable → placeholder

    /** @var array<string, ?string> source path => frozen url, for this request */
    private static array $frozen = [];

    /** Snapshot columns for a new order line. */
    public static function capture(Product $product, ?ProductVariant $variant): array
    {
        $colorIds = $variant ? ProductImages::colorIdsOf(self::withOptions($variant)) : [];
        [$path, $source] = self::pick($product, $colorIds, $variant !== null);
        $url = self::freeze($path);

        return [
            'variant_attributes' => $variant ? (self::attributesOf($variant) ?: null) : null,
            'image_url'          => $url,
            'image_source'       => $url ? $source : self::SRC_NONE,
        ];
    }

    /** [{option_id, slug, type, label, value, color_hex}] — the seller's original text, like product_name. */
    public static function attributesOf(ProductVariant $variant): array
    {
        return self::withOptions($variant)->attributeOptions
            ->filter(fn($o) => $o->attribute)
            ->map(fn($o) => [
                'option_id' => (int) $o->id,
                'slug'      => $o->attribute->slug,
                'type'      => $o->attribute->type,
                'label'     => $o->attribute->getAttributes()['name'] ?? $o->attribute->name,
                'value'     => $o->getAttributes()['value'] ?? $o->value,
                'color_hex' => $o->color_hex,
            ])->values()->all();
    }

    /** "Rouge / M" */
    public static function labelOf(?array $attributes): ?string
    {
        $label = collect($attributes ?? [])->pluck('value')->filter()->join(' / ');
        return $label !== '' ? $label : null;
    }

    /** Color option ids in a variant_attributes snapshot. */
    public static function colorIdsIn(?array $attributes): array
    {
        return collect($attributes ?? [])
            ->filter(fn($a) => ($a['slug'] ?? null) === 'color' || ($a['type'] ?? null) === 'color')
            ->pluck('option_id')->filter()->map(fn($i) => (int) $i)->unique()->sort()->values()->all();
    }

    /**
     * The image to show for a line, as a public-disk path, and how it was chosen.
     *
     * @param array|null $colorIds  colors of the bought variant; [] = known to have no color; null = unknown
     * @param bool       $hasVariant the line was bought as a variant
     * @return array{0: ?string, 1: string}
     */
    public static function pick(?Product $product, ?array $colorIds, bool $hasVariant): array
    {
        if (!$product) return [null, self::SRC_NONE];

        $sets = ProductImages::cachedSets($product);

        if ($colorIds) {
            $registry = [];
            foreach ($sets['color_groups'] as $g) $registry[$g['key']] = $g['color_option_ids'];
            $key = ProductImages::resolveGroupKey($colorIds, $registry);
            foreach ($sets['color_groups'] as $g) {
                if ($g['key'] === $key && $g['images']) return [$g['images'][0]['path'], self::SRC_VARIANT];
            }
        }

        $main = self::mainImage($product, $sets, $hasVariant);
        if (!$main) return [null, self::SRC_NONE];

        $source = match (true) {
            !$hasVariant      => self::SRC_PRODUCT,
            $colorIds === []  => self::SRC_VARIANT,   // size-only variant: sizes share the product's images
            default           => self::SRC_FALLBACK,
        };
        return [$main, $source];
    }

    /** Copies a product image to order-snapshots/ and returns its url path ("/storage/order-snapshots/…"). */
    public static function freeze(?string $path): ?string
    {
        if (!$path) return null;
        if (array_key_exists($path, self::$frozen)) return self::$frozen[$path];

        $disk = Storage::disk('public');
        if (!$disk->exists($path)) return self::$frozen[$path] = null;

        $hash   = sha1($disk->get($path));
        $ext    = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';
        $target = self::DIR . '/' . substr($hash, 0, 2) . "/{$hash}.{$ext}";
        if (!$disk->exists($target)) $disk->copy($path, $target);

        return self::$frozen[$path] = parse_url($disk->url($target), PHP_URL_PATH) ?: $disk->url($target);
    }

    public static function flush(): void
    {
        self::$frozen = [];
    }

    /**
     * The product's main image when the bought color has none: the cover if it
     * belongs to no color, else the first color-less image. A simple product's
     * images are all its own, so it takes the cover whatever it is.
     */
    private static function mainImage(Product $product, array $sets, bool $hasVariant): ?string
    {
        $product->loadMissing('images');
        $cover = $product->images->firstWhere('is_primary', true) ?? $product->images->sortBy('order')->first();
        $neutral = array_column($sets['gallery'], 'path');

        if ($cover && (!$hasVariant || in_array($cover->image_path, $neutral, true))) return $cover->image_path;
        return $neutral[0] ?? null;
    }

    private static function withOptions(ProductVariant $variant): ProductVariant
    {
        return $variant->loadMissing('attributeOptions.attribute');
    }
}
