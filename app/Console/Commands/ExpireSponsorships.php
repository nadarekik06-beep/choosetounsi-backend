<?php
// app/Console/Commands/ExpireSponsorships.php
//
// Artisan command to expire overdue sponsorships and sync product flags.
// Register in app/Console/Kernel.php:
//
//   protected $commands = [
//       \App\Console\Commands\ExpireSponsorships::class,
//   ];
//
//   $schedule->command('sponsorships:expire')->hourly();

namespace App\Console\Commands;

use App\Models\Sponsorship;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireSponsorships extends Command
{
    protected $signature   = 'sponsorships:expire';
    protected $description = 'Expire overdue sponsorships and sync product sponsored flags';

    public function handle(): int
    {
        try {
            $count = Sponsorship::expireOverdue();
            $this->info("Expired {$count} sponsorship(s).");
            Log::info("[ExpireSponsorships] Expired {$count} sponsorship(s).");
        } catch (\Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            Log::error('[ExpireSponsorships] ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}