<?php

namespace App\Console\Commands\Intigo;

use App\Services\Intigo\IntigoRegions;
use Illuminate\Console\Command;

/**
 * Fetches Intigo's governorates and delegations (public endpoints, no API
 * key, read-only) into intigo_cities / intigo_districts. Safe to re-run:
 * rows are upserted on Intigo's ids.
 */
class SyncRegions extends Command
{
    protected $signature = 'intigo:sync-regions {--neighborhoods : also fetch the neighborhoods (one call per delegation, slow)}';

    protected $description = 'Fetch Intigo cities and districts (and optionally neighborhoods) into the local tables';

    public function handle(IntigoRegions $regions): int
    {
        $this->info('Fetching Intigo regions from ' . config('intigo.base_url') . '/regions …');
        try {
            $counts = $regions->sync((bool) $this->option('neighborhoods'), function (string $city, int $districts) {
                $this->line("  {$city}: {$districts} delegations");
            });
        } catch (\Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info("Done: {$counts['cities']} governorates, {$counts['districts']} delegations"
            . ($this->option('neighborhoods') ? ", {$counts['neighborhoods']} neighborhoods" : '') . '.');
        return self::SUCCESS;
    }
}
