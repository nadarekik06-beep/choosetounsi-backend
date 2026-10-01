<?php

namespace App\Console\Commands\Search;

use App\Services\Search\IndexSettings;
use App\Services\Search\SearchUnavailable;
use App\Services\Search\Synonyms;
use Illuminate\Console\Command;

/** Push index settings and resources/search/synonyms.txt to Meilisearch (no reindex needed). */
class SyncSearchSettings extends Command
{
    protected $signature = 'search:sync-settings';
    protected $description = 'Push search settings and the synonym file to Meilisearch';

    public function handle(IndexSettings $settings, Synonyms $synonyms): int
    {
        try {
            $settings->sync();
        } catch (SearchUnavailable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info(count($synonyms->groups()) . ' synonym groups pushed (' . count($synonyms->forMeilisearch()) . ' terms).');
        return self::SUCCESS;
    }
}
