<?php

namespace App\Console\Commands\Search;

use App\Models\Product;
use App\Services\Search\BoilerplateFilter;
use App\Services\Search\IndexSettings;
use App\Services\Search\MeiliClient;
use App\Services\Search\ProductDocument;
use Illuminate\Console\Command;

/**
 * Full rebuild of the Meilisearch product index (semantic fallback) from MySQL. Day-to-day
 * changes are synced by Scout; this is for first setup, after restoring a backup, and as a
 * nightly safety net. Photos are not here: php artisan image-search:rebuild.
 */
class ReindexSearch extends Command
{
    protected $signature = 'search:reindex';
    protected $description = 'Rebuild the Meilisearch product index from the database';

    public function handle(MeiliClient $meili, IndexSettings $settings, ProductDocument $documents,
                           BoilerplateFilter $boilerplate): int
    {
        $start = microtime(true);
        if (!$meili->healthy()) {
            $this->error('Meilisearch is not reachable at ' . config('search.meilisearch.host') . '.');
            $this->line('Start it first (choosetounsi-ai-service/README.md, "Running Meilisearch").');
            return self::FAILURE;
        }

        $this->info('Settings and synonyms...');
        $boilerplate->forget();
        $boilerplate->shingles();
        $settings->sync();

        $this->info('Products...');
        $live = Product::where('is_approved', true)->where('is_active', true);
        $liveIds = [];
        $withVector = 0;
        $tasks = [];
        $bar = $this->output->createProgressBar((clone $live)->count());
        $live->with(ProductDocument::RELATIONS)->orderBy('id')
            ->chunk(200, function ($chunk) use ($documents, $meili, &$liveIds, &$withVector, &$tasks, $bar) {
                $docs = $documents->buildMany($chunk);
                foreach ($docs as $doc) {
                    $liveIds[] = $doc['id'];
                    $withVector += empty($doc['_vectors']['text']) ? 0 : 1;
                }
                $tasks[] = $meili->addDocuments('products', $docs);
                $bar->advance($chunk->count());
            });
        $bar->finish();
        $this->newLine();

        $stale = array_values(array_diff($meili->documentIds('products'), $liveIds));
        if ($stale) {
            $tasks[] = $meili->deleteDocuments('products', $stale);
        }
        foreach ($tasks as $task) {
            $meili->wait($task);
        }

        $this->table(['', ''], [
            ['Live products indexed', count($liveIds)],
            ['...with a text vector', config('search.semantic.enabled') ? $withVector : 'semantic search off'],
            ['Removed from the index', count($stale)],
            ['Time', round(microtime(true) - $start, 1) . ' s'],
        ]);

        if (config('search.semantic.enabled') && $withVector < count($liveIds)) {
            $this->warn((count($liveIds) - $withVector) . ' products have no text vector (embedding service down?). Keyword search still works; run again later.');
        }
        return self::SUCCESS;
    }
}
