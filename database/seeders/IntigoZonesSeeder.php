<?php

namespace Database\Seeders;

use App\Services\Intigo\IntigoZones;
use Illuminate\Database\Seeder;

/**
 * Fallback when Intigo can't be reached: loads the saved copy of Intigo's own
 * regions (database/data/intigo_regions.json, written by intigo:sync-zones)
 * and rebuilds intigo_zones. Never a hand-made list.
 *
 *   php artisan db:seed --class=IntigoZonesSeeder
 */
class IntigoZonesSeeder extends Seeder
{
    public function run(IntigoZones $zones): void
    {
        $counts = $zones->importSnapshot();
        $this->command?->info("intigo_zones: {$counts['delegations']} délégations, {$counts['secteurs']} secteurs");
    }
}
