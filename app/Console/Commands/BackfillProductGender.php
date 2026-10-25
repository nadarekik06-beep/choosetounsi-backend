<?php

namespace App\Console\Commands;

use App\Services\Gender\GenderResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fills products.gender / products.gender_source from the seller's gender attribute,
 * the admin override, or the subcategory's gender menu links.
 *
 *   php artisan products:backfill-gender --dry-run   # counts only, writes nothing
 *   php artisan products:backfill-gender             # writes changed rows only
 *
 * Idempotent: running it twice changes nothing the second time. Touches only the two
 * gender columns (query builder: no model events, updated_at untouched). Includes
 * soft-deleted products so they come back correct if restored.
 */
class BackfillProductGender extends Command
{
    protected $signature = 'products:backfill-gender
                            {--dry-run : Show what would change without writing}
                            {--chunk=500 : Rows per batch}';

    protected $description = 'Compute the browsing gender (women/men/unisex/kids) of every product';

    public function handle(GenderResolver $resolver): int
    {
        $dry    = (bool) $this->option('dry-run');
        $chunk  = max(50, (int) $this->option('chunk'));
        $attrId = $resolver->attributeId();

        if (!Schema::hasColumn('products', 'gender')) {
            $this->error('products.gender is missing — run the migrations first.');
            return self::FAILURE;
        }

        $inherited = $resolver->inheritedMap();

        $byGender = [];  // gender  → n
        $bySource = [];  // source  → n
        $matrix   = [];  // gender|source → n
        $unknown  = [];  // stored value that maps to nothing → n
        $total = $changed = 0;

        DB::table('products')->orderBy('id')
            ->select(['id', 'subcategory_id', 'gender', 'gender_source', 'gender_override'])
            ->chunkById($chunk, function ($rows) use (
                $resolver, $attrId, $inherited, $dry,
                &$byGender, &$bySource, &$matrix, &$unknown, &$total, &$changed
            ) {
                $raw = $attrId
                    ? DB::table('product_attribute_values')->where('attribute_id', $attrId)
                        ->whereIn('product_id', $rows->pluck('id'))->pluck('value', 'product_id')
                    : collect();

                foreach ($rows as $p) {
                    $total++;
                    $stored = $raw[$p->id] ?? null;
                    [$gender, $source] = $resolver->resolve($p, $stored, $inherited);

                    if ($stored !== null && trim((string) $stored) !== '' && $resolver->fromStoredValue($stored) === null) {
                        $unknown[(string) $stored] = ($unknown[(string) $stored] ?? 0) + 1;
                    }

                    $g = $gender ?? 'none';
                    $s = $source ?? 'none';
                    $byGender[$g] = ($byGender[$g] ?? 0) + 1;
                    $bySource[$s] = ($bySource[$s] ?? 0) + 1;
                    $matrix["{$g}|{$s}"] = ($matrix["{$g}|{$s}"] ?? 0) + 1;

                    if ($p->gender !== $gender || $p->gender_source !== $source) {
                        $changed++;
                        if (!$dry) {
                            DB::table('products')->where('id', $p->id)
                                ->update(['gender' => $gender, 'gender_source' => $source]);
                        }
                    }
                }
            });

        $this->info(($dry ? '[DRY RUN] ' : '') . "Products scanned: {$total}");

        $this->table(['Gender', 'Products'], $this->rows($byGender, ['women', 'men', 'unisex', 'kids', 'none']));
        $this->table(['Source', 'Products'], $this->rows($bySource, ['seller', 'inherited', 'admin', 'none']));

        $mrows = [];
        foreach (['women', 'men', 'unisex', 'kids', 'none'] as $g) {
            $mrows[] = [$g, $matrix["{$g}|seller"] ?? 0, $matrix["{$g}|inherited"] ?? 0, $matrix["{$g}|admin"] ?? 0, $matrix["{$g}|none"] ?? 0];
        }
        $this->table(['Gender \\ Source', 'seller', 'inherited', 'admin', 'none'], $mrows);

        if ($unknown) {
            $this->warn('Stored gender values not recognised (treated as no seller gender):');
            $this->table(['Stored value', 'Products'], collect($unknown)->map(fn ($n, $v) => [$v, $n])->values()->all());
        }

        $this->line($dry
            ? "Would update {$changed} product(s). Nothing was written."
            : "Updated {$changed} product(s).");

        if (!$dry && $changed > 0) {
            app(\App\Services\Gender\GenderMenu::class)->flush();
        }

        return self::SUCCESS;
    }

    private function rows(array $counts, array $order): array
    {
        return array_map(fn ($k) => [$k, $counts[$k] ?? 0], $order);
    }
}
