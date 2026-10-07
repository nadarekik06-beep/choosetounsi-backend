<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for product images.
 *
 * A product has two kinds of images, like on Amazon / Trendyol:
 *   gallery      — no color (products without a color attribute, or general shots)
 *   color group  — shared by every size of one color (or multi-color) combination;
 *                  the first image is that color's main image
 * There are no per-size images: legacy rows with a variant_id are read as part of
 * that variant's color group (or the gallery when the variant has no color).
 *
 * Color images are stored with one row per color of the group (all rows share the
 * file path), so the storefront's exact-match lookup and a lookup by any single
 * color both find them. A logical image is addressed by its lowest row id.
 *
 * Saving uses a manifest — the full ordered list per set — so add / delete /
 * replace / reorder happen together when the form is saved:
 *   ['gallery' => [items], 'color_groups' => [['color_option_ids' => [..], 'items' => [items]]]]
 *   item = ['id' => existing image id] | ['upload' => file key, 'replaces' => old id?]
 */
class ProductImages
{
    public const GALLERY_MAX = 8;
    public const GROUP_MAX   = 5;
    public const MAX_COLORS  = 5;
    public const MAX_FILE_KB = 5120;
    public const FILE_RULE   = 'image|mimes:jpg,jpeg,png,webp,gif|max:5120';

    /** @var array<int, array> image sets per product for this request */
    private static array $memo = [];

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * @return array{gallery: array, color_groups: array, cover_id: ?int}
     *   gallery / color_groups[].images: [['id', 'path', 'url']], color_groups keyed in display order
     */
    public static function sets(Product $product): array
    {
        $product->loadMissing(['images', 'variants.attributeOptions.attribute']);

        $images = $product->images->sortBy(fn($i) => [$i->order, $i->id])->values();

        $registry = [];            // color group key => ids, from the variants
        $variantKey = [];          // variant id => its color group key (or null)
        foreach ($product->variants as $v) {
            $ids = self::colorIdsOf($v);
            $variantKey[$v->id] = $ids ? implode('|', $ids) : null;
            if ($ids) $registry[implode('|', $ids)] = $ids;
        }

        $gallery   = [];
        $groups    = [];
        $seen      = [];
        $byPath    = [];
        $legacyKey = [];   // path => color group key, for legacy per-variant images

        foreach ($images as $img) {
            if ($img->color_option_id) {
                $byPath[$img->image_path][] = $img;
                continue;
            }
            // Legacy per-variant image → its color group, else the gallery
            $key = $img->variant_id ? ($variantKey[$img->variant_id] ?? null) : null;
            if ($key) {
                $byPath[$img->image_path][] = $img;       // resolved below via the variant's key
                $legacyKey[$img->image_path] = $key;
                continue;
            }
            if (isset($seen[$img->image_path])) continue;
            $seen[$img->image_path] = true;
            $gallery[] = self::item($img);
        }

        foreach ($byPath as $path => $rows) {
            $key = $legacyKey[$path] ?? null;
            if ($key === null || collect($rows)->contains(fn($r) => $r->color_option_id)) {
                $stored = collect($rows)->pluck('color_option_id')->filter()->map(fn($i) => (int) $i)->unique()->sort()->values()->all();
                $key = $stored ? self::resolveGroupKey($stored, $registry) : $key;
            }
            $first = collect($rows)->sortBy('id')->first();
            $groups[$key] ??= ['key' => $key, 'color_option_ids' => array_map('intval', explode('|', $key)), 'images' => []];
            $groups[$key]['images'][] = self::item($first) + ['_order' => collect($rows)->min('order')];
        }
        foreach ($groups as &$g) {
            usort($g['images'], fn($a, $b) => [$a['_order'], $a['id']] <=> [$b['_order'], $b['id']]);
            $g['images'] = array_map(fn($i) => array_diff_key($i, ['_order' => 1]), $g['images']);
        }
        unset($g);

        $cover = $images->firstWhere('is_primary', true);

        return [
            'gallery'      => $gallery,
            'color_groups' => array_values($groups),
            'cover_id'     => $cover?->id,
        ];
    }

    /**
     * Main image of the variant's color group (cart, checkout, orders, favorites,
     * invoices), else the product cover. Size never changes the image.
     * Returns a Storage::url() path ("/storage/…").
     */
    public static function thumbnailFor(?Product $product, ?ProductVariant $variant = null): ?string
    {
        if (!$product) return null;

        $sets = self::cachedSets($product);

        if ($variant) {
            $variant->loadMissing('attributeOptions.attribute');
            $ids = self::colorIdsOf($variant);
            if ($ids) {
                $key = implode('|', $ids);
                foreach ($sets['color_groups'] as $g) {
                    if ($g['key'] === $key && $g['images']) return Storage::url($g['images'][0]['path']);
                }
            }
        }

        $product->loadMissing('images');
        $cover = $product->images->firstWhere('is_primary', true) ?? $product->images->sortBy('order')->first();
        return $cover ? Storage::url($cover->image_path) : null;
    }

    /** sets(), computed once per product for this request. */
    public static function cachedSets(Product $product): array
    {
        return self::$memo[$product->id] ??= self::sets($product);
    }

    /** Color group keys ("5|7") of a set of variant rows (option id lists). */
    public static function groupKeysFor(iterable $optionIdLists): array
    {
        $colorIds = \App\Models\AttributeOption::whereHas('attribute', fn($q) => $q->where('slug', 'color')->orWhere('type', 'color'))
            ->pluck('id')->flip();
        $keys = [];
        foreach ($optionIdLists as $ids) {
            $c = array_values(array_filter(array_map('intval', (array) $ids), fn($i) => isset($colorIds[$i])));
            if (!$c) continue;
            sort($c);
            $keys[implode('|', array_unique($c))] = true;
        }
        // PHP turns numeric-string keys ("146") into ints — keys are always strings
        return array_map('strval', array_keys($keys));
    }

    // ── Validate ─────────────────────────────────────────────────────────────

    /**
     * Adds manifest errors to $v (keys under $prefix, e.g. "images.gallery.2").
     *
     * @param array|null $groupKeys       color groups the product will have; null = don't check
     * @param bool       $requireEachColor every color group needs at least one image
     */
    public static function validate($v, Product $product, array $manifest, array $uploads, ?array $groupKeys = null,
                                    bool $requireEachColor = false, string $prefix = 'images'): void
    {
        $manifest += ['gallery' => [], 'color_groups' => []];
        if (!is_array($manifest['gallery']) || !is_array($manifest['color_groups'])) {
            $v->errors()->add($prefix, 'Invalid image data.');
            return;
        }

        $ownIds  = $product->exists ? $product->images()->pluck('id')->flip() : collect();
        $seenIds = [];
        $seenUp  = [];

        $check = function (array $items, string $field, int $max, string $maxMsg) use ($v, $ownIds, $uploads, &$seenIds, &$seenUp) {
            if (count($items) > $max) $v->errors()->add($field, $maxMsg);
            foreach (array_values($items) as $j => $item) {
                if (!is_array($item)) { $v->errors()->add("$field.$j", 'Invalid image entry.'); continue; }
                if (!empty($item['id'])) {
                    $id = (int) $item['id'];
                    if (!isset($ownIds[$id])) $v->errors()->add("$field.$j", 'This image does not belong to the product.');
                    if (isset($seenIds[$id])) $v->errors()->add("$field.$j", 'The same image is used twice.');
                    $seenIds[$id] = true;
                } elseif (!empty($item['upload'])) {
                    $key  = (string) $item['upload'];
                    $file = $uploads[$key] ?? null;
                    if (!$file instanceof UploadedFile || !$file->isValid()) {
                        $v->errors()->add("$field.$j", 'The uploaded file is missing or failed to upload.');
                    } else {
                        $fv = validator(['f' => $file], ['f' => self::FILE_RULE]);
                        if ($fv->fails()) $v->errors()->add("$field.$j", $file->getClientOriginalName() . ': ' . $fv->errors()->first('f'));
                    }
                    if (isset($seenUp[$key])) $v->errors()->add("$field.$j", 'The same upload is used twice.');
                    $seenUp[$key] = true;
                } else {
                    $v->errors()->add("$field.$j", 'Invalid image entry.');
                }
            }
        };

        $check($manifest['gallery'], "$prefix.gallery", self::GALLERY_MAX, 'At most ' . self::GALLERY_MAX . ' product images.');

        $sent = [];
        foreach (array_values($manifest['color_groups']) as $g => $group) {
            $ids = array_values(array_unique(array_map('intval', (array) ($group['color_option_ids'] ?? []))));
            sort($ids);
            $key = implode('|', $ids);
            if (!$ids || count($ids) > self::MAX_COLORS) {
                $v->errors()->add("$prefix.color_groups.$g", 'Invalid color group.');
                continue;
            }
            if (isset($sent[$key])) $v->errors()->add("$prefix.color_groups.$g", 'Duplicate color group.');
            if ($groupKeys !== null && !in_array($key, $groupKeys, true) && !empty($group['items'])) {
                $v->errors()->add("$prefix.color_groups.$g", 'No variant uses this color anymore — remove its images.');
            }
            $sent[$key] = count((array) ($group['items'] ?? []));
            $check((array) ($group['items'] ?? []), "$prefix.color_groups.$g.items", self::GROUP_MAX,
                'At most ' . self::GROUP_MAX . ' images per color.');
        }

        if ($requireEachColor && $groupKeys) {
            $missing = array_values(array_filter($groupKeys, fn($k) => empty($sent[$k])));
            if ($missing) {
                $names = \App\Models\AttributeOption::whereIn('id', collect($missing)->flatMap(fn($k) => explode('|', $k)))->pluck('value', 'id');
                $label = collect($missing)->map(fn($k) => collect(explode('|', $k))->map(fn($id) => $names[$id] ?? "#$id")->implode(' + '))->implode(', ');
                $v->errors()->add("$prefix.color_groups", __('seller.product.color_images_required', ['colors' => $label]));
            }
        }
    }

    // ── Write ────────────────────────────────────────────────────────────────

    /** Upload keys referenced by the manifest. */
    public static function referencedUploads(array $manifest): array
    {
        return collect($manifest['gallery'] ?? [])
            ->merge(collect($manifest['color_groups'] ?? [])->pluck('items')->flatten(1))
            ->pluck('upload')->filter()->map(fn($k) => (string) $k)->unique()->values()->all();
    }

    /** Stores the referenced uploads; returns key => storage path. */
    public static function storeUploads(array $manifest, array $uploads): array
    {
        $stored = [];
        foreach (self::referencedUploads($manifest) as $key) {
            $stored[$key] = $uploads[$key]->store('products', 'public');
        }
        return $stored;
    }

    /** Distinct images the product will have after the manifest is applied. */
    public static function countAfter(array $manifest): int
    {
        return count($manifest['gallery'] ?? []) + collect($manifest['color_groups'] ?? [])->sum(fn($g) => count($g['items'] ?? []));
    }

    /**
     * Rewrites every image row of the product to match the manifest (call inside a
     * transaction, after the variants are saved). Returns the file paths the product
     * no longer uses — delete them with deleteUnusedFiles() once committed.
     */
    public static function apply(Product $product, array $manifest, array $stored): array
    {
        $rows       = ProductImage::where('product_id', $product->id)->get();
        $pathOf     = $rows->pluck('image_path', 'id');
        $rowsByPath = $rows->groupBy('image_path');

        $targets = [];   // path => [[variant_id, color_option_id], …] in display order
        $resolve = fn(array $item) => !empty($item['id']) ? $pathOf[(int) $item['id']] : $stored[(string) $item['upload']];

        foreach ($manifest['gallery'] ?? [] as $item) {
            $targets[$resolve($item)] = [[null, null]];
        }
        foreach ($manifest['color_groups'] ?? [] as $group) {
            $ids = array_values(array_unique(array_map('intval', $group['color_option_ids'])));
            sort($ids);
            foreach ($group['items'] ?? [] as $item) {
                $targets[$resolve($item)] = array_map(fn($cid) => [null, $cid], $ids);
            }
        }

        $order = 0;
        $cover = null;
        foreach ($targets as $path => $specs) {
            $existing = ($rowsByPath[$path] ?? collect())->sortBy('id')->values();
            foreach ($specs as $i => [$vid, $cid]) {
                $attrs = ['variant_id' => $vid, 'color_option_id' => $cid, 'order' => $order, 'is_primary' => false];
                if ($row = $existing->get($i)) {
                    $row->fill($attrs);
                    if ($row->isDirty()) $row->save();
                } else {
                    $row = ProductImage::create($attrs + ['product_id' => $product->id, 'image_path' => $path]);
                }
                $cover ??= $row;
            }
            foreach ($existing->slice(count($specs)) as $extra) $extra->delete();
            $order++;
        }

        // Cover = first gallery image, else the first color group's main image
        if ($cover) ProductImage::whereKey($cover->id)->update(['is_primary' => true]);

        $orphans = [];
        foreach ($rowsByPath as $path => $group) {
            if (isset($targets[$path])) continue;
            ProductImage::whereIn('id', $group->pluck('id'))->delete();
            $orphans[] = $path;
        }

        // Bulk deletes skip model events: tell photo search (creates/updates already did).
        if ($orphans && config('search.indexing')) {
            \App\Jobs\IndexProductImages::dispatch($product->id);
        }

        unset(self::$memo[$product->id]);
        ProductCardImages::flush();
        return $orphans;
    }

    /** Deletes files no image row references anymore. */
    public static function deleteUnusedFiles(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            if (!ProductImage::where('image_path', $path)->exists()) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    public static function flush(): void
    {
        self::$memo = [];
        ProductCardImages::flush();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public static function colorIdsOf(ProductVariant $v): array
    {
        return $v->attributeOptions
            ->filter(fn($o) => $o->attribute && ($o->attribute->slug === 'color' || $o->attribute->type === 'color'))
            ->pluck('id')->map(fn($i) => (int) $i)->unique()->sort()->values()->all();
    }

    /** Same resolution as the storefront: exact variant group, else the smallest group containing the ids. */
    public static function resolveGroupKey(array $stored, array $registry): string
    {
        $key = implode('|', $stored);
        if (isset($registry[$key])) return $key;

        $best = null;
        foreach ($registry as $k => $ids) {
            if (count(array_intersect($stored, $ids)) === count($stored)
                && ($best === null || count($ids) < count($registry[$best]))) {
                $best = $k;
            }
        }
        return $best ?? $key;
    }

    private static function item(ProductImage $img): array
    {
        return ['id' => $img->id, 'path' => $img->image_path, 'url' => url(Storage::url($img->image_path))];
    }
}
