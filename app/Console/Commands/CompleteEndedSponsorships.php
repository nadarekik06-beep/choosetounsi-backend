<?php

namespace App\Console\Commands;

use App\Services\Ads\SponsorshipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Completes open campaigns whose end date has passed (seller gets the results
 * summary) and syncs the products' sponsor flags. Scheduled every 5 minutes
 * (app/Console/Kernel.php) — read paths never write; they filter with
 * Sponsorship::live() and the ad server's own end-date check in the meantime.
 *
 *   php artisan ads:complete-ended      (alias: sponsorships:expire)
 */
class CompleteEndedSponsorships extends Command
{
    protected $signature   = 'ads:complete-ended';
    protected $description = 'Complete campaigns whose end date has passed';

    public function __construct()
    {
        parent::__construct();
        $this->setAliases(['sponsorships:expire']);
    }

    public function handle(SponsorshipService $campaigns): int
    {
        try {
            $count = $campaigns->completeEnded();
            $this->info("Completed {$count} campaign(s).");
            if ($count > 0) {
                Log::info("[ads:complete-ended] Completed {$count} campaign(s).");
            }
        } catch (\Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            Log::error('[ads:complete-ended] ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
