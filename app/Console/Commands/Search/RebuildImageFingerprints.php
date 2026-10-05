<?php

namespace App\Console\Commands\Search;

use App\Models\Product;
use App\Services\Search\FingerprintClient;
use App\Services\Search\FingerprintIndex;
use App\Services\Search\ImageIndexer;
use App\Services\Search\SearchUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the photo fingerprints from MySQL + the photo files. Day-to-day changes are synced by
 * the IndexProductImages job; this is for first setup, after a model change or a backup
 * restore, and the nightly safety net. Unchanged photos are not re-embedded.
 *
 *   php artisan image-search:rebuild           new / changed / other-model photos only
 *   php artisan image-search:rebuild --fresh   re-embed every photo
 */
class RebuildImageFingerprints extends Command
{
    protected $signature = 'image-search:rebuild {--fresh : Re-embed every photo, even unchanged ones}';
    protected $description = 'Rebuild the photo search fingerprints (image_fingerprints) and refresh the cached index';

    public function handle(ImageIndexer $indexer, FingerprintClient $client, FingerprintIndex $index): int
    {
        $start = microtime(true);
        $model = $client->model();
        if (!$model) {
            $this->error('The AI service is not reachable at ' . config('services.ai.url') . ' (GET /health).');
            $this->line('Start it first: choosetounsi-ai-service (run.ps1 locally, systemctl start choosetounsi-ai on the server).');
            return self::FAILURE;
        }
        $this->info("Model: $model");

        $ids = Product::withTrashed()->orderBy('id')->pluck('id');
        $totals = ['indexed' => 0, 'embedded' => 0, 'failed' => 0, 'removed' => 0];
        $bar = $this->output->createProgressBar($ids->count());
        try {
            foreach ($ids as $id) {
                $r = $indexer->syncProduct($id, (bool) $this->option('fresh'), bump: false);
                $totals['indexed'] += $r['indexed'];
                $totals['embedded'] += $r['embedded'];
                $totals['failed'] += $r['failed'];
                $totals['removed'] += $r['removed'] && $r['changed'] ? 1 : 0;
                $bar->advance();
            }
        } catch (SearchUnavailable $e) {
            $bar->finish();
            $this->newLine();
            $index->bump();
            $this->error('Stopped early, the AI service failed: ' . $e->getMessage());
            return self::FAILURE;
        }
        $bar->finish();
        $this->newLine();

        $index->bump();
        $live = $index->get();
        $other = DB::table('image_fingerprints')->where('model', '!=', $model)->count();

        $this->table(['', ''], [
            ['Searchable photos (live products, active sellers)', $live['count']],
            ['Categories with a centroid', count($live['centroids'])],
            ['Photos embedded now', $totals['embedded']],
            ['Products removed (not live)', $totals['removed']],
            ['Photo files missing / unreadable', $totals['failed']],
            ['Rows from another model', $other],
            ['Time', round(microtime(true) - $start, 1) . ' s'],
        ]);
        return self::SUCCESS;
    }
}
