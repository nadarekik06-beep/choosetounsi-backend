<?php

namespace App\Console\Commands;

use App\Services\Ads\AdOptimizer;
use Illuminate\Console\Command;

/**
 * Daily campaign check-up: tips for the seller, placement weights for the ad
 * server, low-performance alerts. Scheduled at 04:00 Africa/Tunis.
 *
 *   php artisan ads:optimize
 */
class OptimizeAdCampaigns extends Command
{
    protected $signature   = 'ads:optimize';
    protected $description = 'Refresh campaign tips and placement weights; alert sellers about weak campaigns';

    public function handle(AdOptimizer $optimizer): int
    {
        $this->info('Checked ' . $optimizer->run() . ' campaign(s).');
        return self::SUCCESS;
    }
}
