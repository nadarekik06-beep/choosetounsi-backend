<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductCardImages;
use App\Services\ProductImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Products that have images but no cover row (is_primary) get one: the first
 * gallery image, else the first color (variant) image — the same pick as
 * ProductImages::coverOf(). Only the is_primary flag changes; no image row is
 * added, removed or reordered.
 *
 *   php artisan products:fix-cover-images                       # dry run, lists what would change
 *   php artisan products:fix-cover-images --slug=sandales-elegance --apply
 *   php artisan products:fix-cover-images --apply
 */
class FixProductCoverImages extends Command
{
    protected $signature = 'products:fix-cover-images
                            {--slug= : Only this product}
                            {--apply : Write the changes (default: dry run)}';

    protected $description = 'Set a cover image on products that have images but no is_primary image';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $query = Product::withTrashed()
            ->whereHas('images')
            ->whereDoesntHave('images', fn ($q) => $q->where('is_primary', true))
            ->with('images')
            ->orderBy('id');
        if ($slug = $this->option('slug')) $query->where('slug', $slug);

        $rows = [];
        foreach ($query->get() as $product) {
            $cover = ProductImages::pickCover($product->images);
            if (!$cover) continue;
            $kind = ($cover->color_option_id || $cover->variant_id) ? 'color image' : 'gallery image';
            if ($apply) {
                DB::transaction(fn () => ProductImage::whereKey($cover->id)->update(['is_primary' => true]));
            }
            $rows[] = [$product->id, $product->slug, $product->seller_id, $cover->id, $kind, $cover->image_path];
        }
        if ($apply && $rows) ProductImages::flush();

        $noImages = Product::whereDoesntHave('images')->where('is_approved', true)->whereNull('deleted_at')
            ->when($slug, fn ($q) => $q->where('slug', $slug))->orderBy('id')->get(['id', 'slug', 'seller_id']);

        $this->info(($apply ? 'Fixed' : 'Would fix (dry run)') . ': ' . count($rows) . ' product(s) without a cover image.');
        if ($rows) $this->table(['product', 'slug', 'seller', 'cover image id', 'taken from', 'path'], $rows);

        if ($noImages->isNotEmpty()) {
            $this->warn("Approved products with no image at all (can't be fixed here, the seller must upload one): " . $noImages->count());
            $this->table(['product', 'slug', 'seller'], $noImages->map(fn ($p) => [$p->id, $p->slug, $p->seller_id])->all());
        }
        if (!$apply && $rows) $this->line('Run again with --apply to write the changes.');

        return self::SUCCESS;
    }
}
