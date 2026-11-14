<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Flags the categories / subcategories where "changed my mind" returns are not allowed
 * (underwear, swimwear, cosmetics, food, hygiene, personalised, baby bath care).
 * Dry run by default: prints exactly what would change. `--apply` writes.
 * Matching is by slug; slugs missing from this database are reported and skipped.
 */
class ReturnsFlagCategories extends Command
{
    protected $signature = 'returns:flag-categories {--apply : write the flags (default: dry run)}';
    protected $description = 'Flag the categories where "changed my mind" returns are not allowed (dry run first)';

    /** Whole categories flagged: every subcategory inherits. */
    public const CATEGORIES = ['food-grocery', 'beauty-personal-care'];

    /** Subcategories flagged one by one: category slug => subcategory slugs. */
    public const SUBCATEGORIES = [
        'fashion-clothing' => ['bras', 'panties', 'men-s-underwear', 'nightdresses-robes', 'socks', 'tights', 'swimwear', 'burkinis'],
        'health-wellness'  => ['feminine-care', 'intimate-hygiene', 'supplements', 'vitamins', 'sports-nutrition', 'herbal-products'],
        'kids-baby'        => ['diapers-wipes', 'baby-food', 'baby-bath-care'],
        'pets'             => ['dog-food', 'cat-food'],
        'weddings-events'  => ['personalized-gifts', 'favors-boxes', 'invitations-cards'],
    ];

    /** Explicit exceptions inside a flagged category (stay allowed). */
    public const EXCEPTIONS = ['beauty-personal-care' => ['hair-styling-tools']];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? '<fg=yellow>APPLY: writing flags</>' : '<fg=cyan>DRY RUN: nothing is written (use --apply)</>');
        $rows = [];

        foreach (self::CATEGORIES as $slug) {
            $cat = Category::where('slug', $slug)->first();
            if (!$cat) { $rows[] = ['category', $slug, 'MISSING: skipped']; continue; }
            $subs = Subcategory::where('category_id', $cat->id)->count();
            $rows[] = ['category', $slug, ($cat->no_changed_mind_return ? 'already ON' : 'ON') . " ({$subs} subcategories inherit)"];
            if ($apply) $cat->forceFill(['no_changed_mind_return' => true])->save();
        }

        foreach (self::EXCEPTIONS as $catSlug => $subSlugs) {
            foreach ($subSlugs as $slug) {
                $sub = $this->sub($catSlug, $slug);
                if (!$sub) { $rows[] = ['exception', "{$catSlug}/{$slug}", 'MISSING: skipped']; continue; }
                $rows[] = ['exception', "{$catSlug}/{$slug}", 'explicit OFF (stays allowed)'];
                if ($apply) $sub->forceFill(['no_changed_mind_return' => false])->save();
            }
        }

        foreach (self::SUBCATEGORIES as $catSlug => $subSlugs) {
            foreach ($subSlugs as $slug) {
                $sub = $this->sub($catSlug, $slug);
                if (!$sub) { $rows[] = ['subcategory', "{$catSlug}/{$slug}", 'MISSING: skipped']; continue; }
                $rows[] = ['subcategory', "{$catSlug}/{$slug}", $sub->no_changed_mind_return ? 'already ON' : 'ON'];
                if ($apply) $sub->forceFill(['no_changed_mind_return' => true])->save();
            }
        }

        $this->table(['Level', 'Slug', 'Result'], $rows);
        $this->info($apply ? 'Done.' : 'Dry run only. Re-run with --apply after review.');
        return self::SUCCESS;
    }

    private function sub(string $catSlug, string $slug): ?Subcategory
    {
        $catId = DB::table('categories')->where('slug', $catSlug)->value('id');
        return $catId ? Subcategory::where('category_id', $catId)->where('slug', $slug)->first() : null;
    }
}
