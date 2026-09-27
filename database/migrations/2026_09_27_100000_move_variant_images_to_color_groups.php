<?php

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductImages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-size (per-variant) images are gone: a color's images are shared by all its
 * sizes. Legacy rows with a variant_id move into that variant's color group (or the
 * product gallery when the variant has no color), without duplicates.
 *
 * Nothing is dropped: when a color group would exceed 5 images (or the gallery 8),
 * the product is left untouched and reported, to be sorted out by hand.
 */
class MoveVariantImagesToColorGroups extends Migration
{
    public function up()
    {
        $productIds = ProductImage::whereNotNull('variant_id')->distinct()->pluck('product_id');
        $moved = 0;
        $skipped = [];

        foreach ($productIds as $pid) {
            $product = Product::withTrashed()->with(['images', 'variants.attributeOptions.attribute'])->find($pid);
            if (!$product) continue;

            // sets() already reads legacy variant images as part of their color group
            $sets = ProductImages::sets($product);
            $tooMany = count($sets['gallery']) > ProductImages::GALLERY_MAX
                || collect($sets['color_groups'])->contains(fn($g) => count($g['images']) > ProductImages::GROUP_MAX);
            if ($tooMany) {
                $skipped[] = $pid;
                continue;
            }

            $manifest = [
                'gallery'      => array_map(fn($i) => ['id' => $i['id']], $sets['gallery']),
                'color_groups' => array_map(fn($g) => [
                    'color_option_ids' => $g['color_option_ids'],
                    'items'            => array_map(fn($i) => ['id' => $i['id']], $g['images']),
                ], $sets['color_groups']),
            ];

            $before = ProductImage::where('product_id', $pid)->whereNotNull('variant_id')->count();
            $orphans = DB::transaction(fn() => ProductImages::apply($product, $manifest, []));
            // Duplicate rows only — files still referenced are kept by deleteUnusedFiles
            ProductImages::deleteUnusedFiles($orphans);
            $moved += $before;
        }

        $msg = "[variant images] products with per-variant images: {$productIds->count()}, rows moved: {$moved}"
             . ($skipped ? ', left untouched (over the image limit): products ' . implode(', ', $skipped) : '');
        Log::info($msg);
        echo $msg . PHP_EOL;
    }

    public function down()
    {
        // One-way data move: the per-size layout isn't reconstructed.
    }
}
