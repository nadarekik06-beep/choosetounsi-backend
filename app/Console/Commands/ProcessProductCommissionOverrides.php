<?php

namespace App\Console\Commands;

use App\Services\ProductCommissionOverrideService;
use Illuminate\Console\Command;

/**
 * Hourly housekeeping of product special commissions: switches off the ones that are over
 * (audit log + "ended" notice to the seller) and sends the reminder 3 days before the end.
 *
 * Money never depends on this: CommissionService compares the dates at calculation time,
 * so an offer stops applying at its end date even if this command runs late.
 */
class ProcessProductCommissionOverrides extends Command
{
    protected $signature   = 'commission-overrides:process';
    protected $description = 'Expire product special commissions that are over and remind sellers 3 days before the end';

    public function handle(ProductCommissionOverrideService $service): int
    {
        $r = $service->runDue();
        $this->info("Expired: {$r['expired']}, reminders sent: {$r['reminded']}");
        return self::SUCCESS;
    }
}
