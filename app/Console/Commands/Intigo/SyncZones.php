<?php

namespace App\Console\Commands\Intigo;

use App\Services\Intigo\IntigoRegions;
use App\Services\Intigo\IntigoZones;
use Illuminate\Console\Command;

/**
 * Builds the "Zone (Délégation/Secteur)" list from Intigo's public /regions
 * endpoints (no API key, read-only, fake and live mode alike):
 *
 *   1. governorates + délégations          ~25 calls
 *   2. quartiers (secteurs), per délégation 268 calls, --delay ms apart; resumes
 *      where an interrupted run stopped (--fresh re-fetches everything)
 *   3. intigo_zones rebuilt (idempotent)
 *   4. database/data/intigo_regions.json refreshed (the seeder's fallback)
 *
 * --from-snapshot skips the network and loads that file instead.
 */
class SyncZones extends Command
{
    protected $signature = 'intigo:sync-zones
        {--fresh : re-fetch the quartiers of every délégation, not only the missing ones}
        {--delay=300 : milliseconds between two quartier calls}
        {--from-snapshot : load database/data/intigo_regions.json instead of calling Intigo}
        {--no-snapshot : do not rewrite the snapshot file}';

    protected $description = "Sync Intigo's délégations and secteurs into intigo_zones (resumable, idempotent)";

    public function handle(IntigoRegions $regions, IntigoZones $zones): int
    {
        if ($this->option('from-snapshot')) {
            $counts = $zones->importSnapshot();
            $this->info("Loaded from snapshot: {$counts['delegations']} délégations, {$counts['secteurs']} secteurs ({$counts['deactivated']} deactivated).");
            return self::SUCCESS;
        }

        $this->line('1/3 Governorates and délégations…');
        try {
            $c = $regions->sync(false);
        } catch (\Throwable $e) {
            $this->error('Intigo regions: ' . $e->getMessage());
            $this->line('Nothing changed. Retry later, or load the saved copy: php artisan intigo:sync-zones --from-snapshot');
            return self::FAILURE;
        }
        $this->info("    {$c['cities']} governorates, {$c['districts']} délégations");

        $this->line('2/3 Secteurs (one call per délégation)…');
        $bar = null;
        $result = $zones->syncNeighborhoods((bool) $this->option('fresh'), max(0, (int) $this->option('delay')),
            function (int $done, int $total, string $name, ?int $count) use (&$bar) {
                if (!$bar) {
                    $bar = $this->output->createProgressBar($total);
                    $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
                    $bar->start();
                }
                $bar->setMessage($count === null ? "{$name}: FAILED" : "{$name}: {$count}");
                $bar->advance();
            });
        $bar?->finish();
        $this->newLine();
        $this->info("    {$result['fetched']} délégations fetched ({$result['neighborhoods']} secteurs), {$result['skipped']} already done");
        foreach ($result['failed'] as $id => $error) {
            $this->warn("    délégation {$id}: {$error}");
        }

        $this->line('3/3 Zones…');
        $z = $zones->rebuild();
        $this->info("    intigo_zones: {$z['delegations']} délégations + {$z['secteurs']} secteurs" . ($z['deactivated'] ? ", {$z['deactivated']} no longer listed (deactivated)" : ''));

        if ($result['failed']) {
            $this->warn(count($result['failed']) . ' délégation(s) failed: run the command again to resume them. Snapshot not rewritten.');
            return self::FAILURE;
        }
        if (!$this->option('no-snapshot')) {
            $s = $zones->exportSnapshot();
            $this->info("Snapshot written: " . IntigoZones::SNAPSHOT . " ({$s['districts']} délégations, {$s['neighborhoods']} secteurs)");
        }
        return self::SUCCESS;
    }
}
