<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductTranslator;
use Illuminate\Console\Command;

/**
 * Backfill / refresh the cached Groq translations of product names and descriptions.
 *
 *   php artisan products:translate              live products whose text changed or was never translated
 *   php artisan products:translate --id=38      one product
 *   php artisan products:translate --force      re-translate everything (uses the Groq daily budget)
 */
class TranslateProducts extends Command
{
    protected $signature = 'products:translate {--id=* : Only these product ids} {--limit=100 : Max products per run} {--force : Re-translate even when up to date}';

    protected $description = 'Translate product names and descriptions (fr, ar, en) with Groq and cache them in the database';

    public function handle(ProductTranslator $translator): int
    {
        $force = (bool) $this->option('force');

        $query = Product::query()->where('is_approved', true)->where('is_active', true)->orderBy('id');
        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        }

        $products = $query->get()
            ->filter(fn ($p) => $force || ProductTranslator::needsTranslation($p))
            ->take((int) $this->option('limit'));

        if ($products->isEmpty()) {
            $this->info('Nothing to translate.');
            return self::SUCCESS;
        }

        $done = 0;
        foreach ($products as $product) {
            $ok = $translator->translate($product, $force);
            $this->line(($ok ? '<info>✓</info> ' : '<comment>✗</comment> ') . "#{$product->id} " . $product->getAttributes()['name']);
            $done += $ok ? 1 : 0;
        }

        $this->info("Translated {$done} / {$products->count()} product(s).");

        return self::SUCCESS;
    }
}
