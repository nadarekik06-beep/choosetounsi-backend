<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Subcategory;
use App\Services\Catalog\SubcategoryBar;
use App\Services\Gender\GenderMenu;
use App\Services\Recommendation\RelatedProducts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merge a duplicate subcategory into the one that stays (same category).
 *
 * Moves every product (soft-deleted included), daily stats and image-search logs,
 * re-points menu items / gender images the target lacks, then DEACTIVATES the source
 * (is_active = false). Nothing is deleted: re-enable it in the admin panel to undo the
 * hiding; the product moves would have to be reverted by hand (ids are printed).
 *
 * Refuses when the source has a variant/info attribute the target doesn't have, so no
 * product loses its sizes, colours or details.
 *
 *   php artisan subcategories:merge fashion-clothing jackets-2 jackets            # dry run
 *   php artisan subcategories:merge fashion-clothing jackets-2 jackets --apply
 */
class MergeSubcategories extends Command
{
    protected $signature = 'subcategories:merge
                            {category : Category slug}
                            {from : Subcategory slug to empty and deactivate}
                            {into : Subcategory slug that keeps the products}
                            {--apply : Write the changes (default: dry run)}';

    protected $description = 'Move the products of a duplicate subcategory into another one and deactivate the duplicate';

    public function handle(): int
    {
        $categoryId = DB::table('categories')->where('slug', $this->argument('category'))->value('id');
        if (!$categoryId) { $this->error('Unknown category.'); return self::FAILURE; }

        $from = Subcategory::where('category_id', $categoryId)->where('slug', $this->argument('from'))->first();
        $into = Subcategory::where('category_id', $categoryId)->where('slug', $this->argument('into'))->first();
        if (!$from || !$into || $from->id === $into->id) {
            $this->error('Both subcategories must exist in that category and be different.');
            return self::FAILURE;
        }

        $this->line("From: #{$from->id} {$from->slug} \"{$from->name}\" (active: " . ($from->is_active ? 'yes' : 'no') . ')');
        $this->line("Into: #{$into->id} {$into->slug} \"{$into->name}\" (active: " . ($into->is_active ? 'yes' : 'no') . ')');

        // Every attribute of the source must exist on the target
        $attrs = fn (int $id) => DB::table('subcategory_attributes')->where('subcategory_id', $id)->pluck('attribute_id')->all();
        $missing = array_diff($attrs($from->id), $attrs($into->id));
        if ($missing) {
            $names = DB::table('attributes')->whereIn('id', $missing)->pluck('slug')->implode(', ');
            $this->error("Refused: \"{$into->slug}\" lacks attributes used by \"{$from->slug}\": {$names}. Add them to it first.");
            return self::FAILURE;
        }

        $products = Product::withTrashed()->where('subcategory_id', $from->id)->orderBy('id')->get(['id', 'name', 'seller_id', 'deleted_at']);
        $this->table(['Product id', 'Name', 'Seller', 'Deleted'],
            $products->map(fn ($p) => [$p->id, mb_substr($p->name, 0, 50), $p->seller_id, $p->deleted_at ? 'yes' : ''])->all());

        $counts = [
            'product_daily_stats'      => DB::table('product_daily_stats')->where('subcategory_id', $from->id)->count(),
            'image_search_logs'        => DB::table('image_search_logs')->where('predicted_subcategory_id', $from->id)->count(),
            'gender_menu_items'        => DB::table('gender_menu_items')->where('subcategory_id', $from->id)->count(),
            'subcategory_gender_images'=> DB::table('subcategory_gender_images')->where('subcategory_id', $from->id)->count(),
        ];
        foreach ($counts as $table => $n) $this->line("  {$table}: {$n} row(s)");

        if (!$this->option('apply')) {
            $this->warn("Dry run: nothing changed. Re-run with --apply to move {$products->count()} product(s) and deactivate \"{$from->slug}\".");
            return self::SUCCESS;
        }

        DB::transaction(function () use ($from, $into, $products) {
            // Model saves, so gender, search text and caches follow through the observers
            foreach ($products as $p) {
                $product = Product::withTrashed()->find($p->id);
                $product->subcategory_id = $into->id;
                $product->save();
            }

            DB::table('product_daily_stats')->where('subcategory_id', $from->id)->update(['subcategory_id' => $into->id]);
            DB::table('image_search_logs')->where('predicted_subcategory_id', $from->id)->update(['predicted_subcategory_id' => $into->id]);

            // Menu items: keep the target's own entry per group, otherwise re-point
            foreach (DB::table('gender_menu_items')->where('subcategory_id', $from->id)->get() as $item) {
                $taken = DB::table('gender_menu_items')->where('group_id', $item->group_id)->where('subcategory_id', $into->id)->exists();
                $taken ? DB::table('gender_menu_items')->where('id', $item->id)->delete()
                       : DB::table('gender_menu_items')->where('id', $item->id)->update(['subcategory_id' => $into->id, 'updated_at' => now()]);
            }
            // Gender images: same rule per gender (the source's image file is left on disk)
            foreach (DB::table('subcategory_gender_images')->where('subcategory_id', $from->id)->get() as $img) {
                $taken = DB::table('subcategory_gender_images')->where('subcategory_id', $into->id)
                    ->where(fn ($q) => $img->gender === null ? $q->whereNull('gender') : $q->where('gender', $img->gender))->exists();
                if (!$taken) DB::table('subcategory_gender_images')->where('id', $img->id)->update(['subcategory_id' => $into->id, 'updated_at' => now()]);
            }

            $from->is_active = false;
            $from->save();
        });

        app(GenderMenu::class)->flush();
        SubcategoryBar::flush();
        RelatedProducts::bump();

        $this->info("Moved {$products->count()} product(s) to \"{$into->slug}\" and deactivated \"{$from->slug}\". Moved ids: "
            . $products->pluck('id')->implode(', '));

        return self::SUCCESS;
    }
}
