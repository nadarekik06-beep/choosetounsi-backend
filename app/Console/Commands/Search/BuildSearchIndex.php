<?php

namespace App\Console\Commands\Search;

use App\Services\Search\SearchIndexer;
use Illuminate\Console\Command;

/** Rebuild the MySQL text index of the search bar (observers keep it current; this is the safety net). */
class BuildSearchIndex extends Command
{
    protected $signature = 'search:build-index';
    protected $description = 'Rebuild product_search_index (storefront text search)';

    public function handle(SearchIndexer $indexer): int
    {
        $started = microtime(true);
        $count = $indexer->rebuild();
        $this->info(sprintf('%d products indexed in %.1fs.', $count, microtime(true) - $started));
        return self::SUCCESS;
    }
}
