<?php

namespace App\Console\Commands\Search;

use App\Models\Product;
use App\Services\Search\BoilerplateFilter;
use App\Services\Search\ImageIndexer;
use App\Services\Search\IndexSettings;
use App\Services\Search\MeiliClient;
use App\Services\Search\ProductDocument;
use App\Services\Search\SearchUnavailable;
use Illuminate\Console\Command;

/**
 * Full rebuild of the search indexes from MySQL. Day-to-day changes are synced by observers
 * and queued jobs; this is for first setup, after restoring a backup, and as a nightly
 * safety net. Unchanged products and photos are not re-embedded (vectors are cached).
 *
 *   php artisan search:reindex              products + photos
 *   php artisan search:reindex --no-images  products only (fast)
 */
class ReindexSearch extends Command
{
    protected $signature = 'search:reindex {--no-images : Skip photos (search by image)}';
    protected $description = 'Rebuild the Meilisearch product and image indexes from the database';

    public function handle(MeiliClient $meili, IndexSettings $settings, ProductDocument $documents,
                           ImageIndexer $images, BoilerplateFilter $boilerplate): int
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

        $photos = ['indexed' => 0, 'failed' => 0, 'error' => null];
        if (!$this->option('no-images')) {
            $this->info('Photos (the first run embeds every photo, later runs only new ones)...');
            $bar = $this->output->createProgressBar(count($liveIds));
            try {
                foreach ($liveIds as $id) {
                    $r = $images->syncProduct($id);
                    $photos['indexed'] += $r['indexed'];
                    $photos['failed'] += $r['failed'];
                    $bar->advance();
                }
                foreach (array_diff(array_unique($meili->imageProductIds()), $liveIds) as $productId) {
                    $meili->deleteByFilter('images', "product_id = $productId");
                }
            } catch (SearchUnavailable $e) {
                $photos['error'] = $e->getMessage();
            }
            $bar->finish();
            $this->newLine();
        }

        $this->table(['', ''], [
            ['Live products indexed', count($liveIds)],
            ['...with a text vector', config('search.semantic.enabled') ? $withVector : 'semantic search off'],
            ['Removed from the index', count($stale)],
            ['Photos indexed', $this->option('no-images') ? 'skipped' : $photos['indexed']],
            ['Photos missing/unreadable', $this->option('no-images') ? '-' : $photos['failed']],
            ['Time', round(microtime(true) - $start, 1) . ' s'],
        ]);

        if ($photos['error']) {
            $this->warn('Photos stopped early: ' . $photos['error']);
            $this->line('Is the embedding service running? (choosetounsi-ai-service: run.ps1)');
            return self::FAILURE;
        }
        if (config('search.semantic.enabled') && $withVector < count($liveIds)) {
            $this->warn((count($liveIds) - $withVector) . ' products have no text vector (embedding service down?). Keyword search still works; run again later.');
        }
        return self::SUCCESS;
    }
}
