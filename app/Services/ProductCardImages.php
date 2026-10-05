<?php

namespace App\Services;

use App\Support\Localization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The image slider of a storefront product card (search, category, homepage,
 * seller store, recommendations, deals, favourites, ads).
 *
 *   card_images   — up to MAX urls: the cover, the other gallery shots, then the
 *                   main image of each color group (one per color, no duplicates)
 *   card_swatches — one per color that has images: [id, name, hex, image]
 *   variants      — active variants [id, stock], added by attach() only when a payload
 *                   lacks them, so the card knows whether "add to cart" needs a choice
 *
 * Two queries for a whole page of products (images, then their color options),
 * memoized per request, so list endpoints stay free of N+1 queries.
 */
class ProductCardImages
{
    public const MAX = 6;
    /** Gallery shots kept before the color images, so colors still get a slot. */
    private const GALLERY_KEEP = 3;

    /** @var array<int, array{card_images: string[], card_swatches: array}> */
    private static array $memo = [];

    /** @var array<int, array> product id => [[id, stock]] */
    private static array $variants = [];

    /** @return array<int, array{card_images: string[], card_swatches: array}> product id => block */
    public static function forIds(array $productIds): array
    {
        $ids     = array_values(array_unique(array_map('intval', array_filter($productIds))));
        $missing = array_values(array_diff($ids, array_keys(self::$memo)));

        if ($missing) {
            $rows = DB::table('product_images')
                ->whereIn('product_id', $missing)
                ->orderBy('product_id')->orderByDesc('is_primary')->orderBy('order')->orderBy('id')
                ->get(['product_id', 'image_path', 'color_option_id', 'variant_id', 'is_primary']);

            $optionIds = $rows->pluck('color_option_id')->filter()->unique()->values()->all();
            $options   = $optionIds
                ? DB::table('attribute_options')->whereIn('id', $optionIds)
                    ->get(['id', 'value', 'value_fr', 'value_ar', 'color_hex', 'order'])->keyBy('id')
                : collect();

            $byProduct = $rows->groupBy('product_id');
            foreach ($missing as $id) {
                self::$memo[$id] = self::build($byProduct->get($id, collect()), $options);
            }
        }

        return array_intersect_key(self::$memo, array_flip($ids));
    }

    /** The block for one product (empty arrays when it has no images). */
    public static function for(int $productId): array
    {
        return self::forIds([$productId])[$productId] ?? ['card_images' => [], 'card_swatches' => []];
    }

    /**
     * Adds card_images / card_swatches to a list of cards (arrays or objects with an id).
     * Arrays are returned with the keys added; objects are changed in place.
     */
    public static function attach(iterable $cards, string $idKey = 'id'): array
    {
        $list   = is_array($cards) ? $cards : iterator_to_array($cards);
        $ids    = array_map(fn ($c) => (int) (is_array($c) ? ($c[$idKey] ?? 0) : ($c->{$idKey} ?? 0)), $list);
        $blocks = self::forIds($ids);
        $needVariants = array_filter($list, fn ($c) => is_array($c) && !array_key_exists('variants', $c));
        $variants = $needVariants ? self::variantsFor($ids) : [];

        foreach ($list as $k => $c) {
            $id    = (int) (is_array($c) ? ($c[$idKey] ?? 0) : ($c->{$idKey} ?? 0));
            $block = $blocks[$id] ?? ['card_images' => [], 'card_swatches' => []];
            if (is_array($c)) {
                $list[$k] = $c + $block + ['variants' => $variants[$id] ?? []];
            } else {
                $c->card_images   = $block['card_images'];
                $c->card_swatches = $block['card_swatches'];
            }
        }
        return $list;
    }

    /** @return array<int, array> product id => active variants [['id', 'stock']] */
    public static function variantsFor(array $productIds): array
    {
        $ids     = array_values(array_unique(array_map('intval', array_filter($productIds))));
        $missing = array_values(array_diff($ids, array_keys(self::$variants)));
        if ($missing) {
            $rows = DB::table('product_variants')->whereIn('product_id', $missing)->where('is_active', true)
                ->orderBy('id')->get(['id', 'product_id', 'stock'])->groupBy('product_id');
            foreach ($missing as $id) {
                self::$variants[$id] = $rows->get($id, collect())
                    ->map(fn ($v) => ['id' => (int) $v->id, 'stock' => (int) $v->stock])->values()->all();
            }
        }
        return array_intersect_key(self::$variants, array_flip($ids));
    }

    public static function flush(): void
    {
        self::$memo = [];
        self::$variants = [];
    }

    private static function build($rows, $options): array
    {
        // The cover always comes first (rows are sorted primary-first), like primary_image_url
        $cover   = $rows->first()?->image_path;
        $gallery = $cover ? [$cover] : [];
        $colors  = [];   // color option id => first image path of its group

        foreach ($rows as $r) {
            if ($r->color_option_id) {
                $colors[(int) $r->color_option_id] ??= $r->image_path;
            } elseif (!$r->variant_id || $r->is_primary) {
                // Legacy per-variant shots (variant_id, no color) are left to the product page
                if (!in_array($r->image_path, $gallery, true)) $gallery[] = $r->image_path;
            }
        }

        // Swatches in the attribute's own order (Red, Blue… as the seller set them up)
        uksort($colors, fn ($a, $b) => [(int) ($options[$a]->order ?? 0), $a] <=> [(int) ($options[$b]->order ?? 0), $b]);

        $paths = array_slice($gallery, 0, $colors ? self::GALLERY_KEEP : self::MAX);
        foreach ($colors as $path) {
            if (!in_array($path, $paths, true)) $paths[] = $path;
        }
        // Spare slots go back to the gallery
        foreach ($gallery as $path) {
            if (count($paths) >= self::MAX) break;
            if (!in_array($path, $paths, true)) $paths[] = $path;
        }
        $paths = array_slice($paths, 0, self::MAX);

        $swatches = [];
        foreach ($colors as $optionId => $path) {
            $opt = $options[$optionId] ?? null;
            if (!$opt) continue;
            $swatches[] = [
                'id'    => $optionId,
                'name'  => Localization::column($opt, 'value'),
                'hex'   => $opt->color_hex,
                'image' => Storage::url($path),
            ];
        }

        return [
            'card_images'   => array_map(fn ($p) => Storage::url($p), $paths),
            'card_swatches' => array_slice($swatches, 0, 8),
        ];
    }
}
