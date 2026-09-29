<?php

namespace App\Console\Commands;

use App\Models\Sponsorship;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ends sponsorships whose end date has passed and syncs the products' sponsor
 * flags. Scheduled every 5 minutes (app/Console/Kernel.php) — read paths never
 * write; they filter with Sponsorship::live() in the meantime.
 *
 *   php artisan ads:complete-ended      (alias: sponsorships:expire)
 */
class CompleteEndedSponsorships extends Command
{
    protected $signature   = 'ads:complete-ended';
    protected $description = 'End overdue sponsorships and sync product sponsored flags';

    public function __construct()
    {
        parent::__construct();
        $this->setAliases(['sponsorships:expire']);
    }

    public function handle(): int
    {
        try {
            $count = Sponsorship::expireOverdue();
            $this->info("Ended {$count} sponsorship(s).");
            if ($count > 0) {
                Log::info("[ads:complete-ended] Ended {$count} sponsorship(s).");
            }
        } catch (\Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            Log::error('[ads:complete-ended] ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
