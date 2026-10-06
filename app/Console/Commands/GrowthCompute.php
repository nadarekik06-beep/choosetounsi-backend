<?php

namespace App\Console\Commands;

use App\Services\GrowthRadar\Benchmarks;
use App\Services\GrowthRadar\GrowthRadar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nightly Growth Radar run: weekly score + action cards for every approved
 * seller (cross-seller benchmarks computed once and shared), then
 * notifications for new high-impact cards (full-feed plans only).
 */
class GrowthCompute extends Command
{
    protected $signature = 'growth:compute
        {--seller=* : Only these seller ids}
        {--no-notify : Do not send notifications}';

    protected $description = 'Compute Growth Radar scores and action cards';

    public function handle(GrowthRadar $radar): int
    {
        $today = GrowthRadar::today();
        GrowthRadar::ensureCalendar($today);
        $bench = new Benchmarks($today);

        $sellers = $this->option('seller') ? array_map('intval', $this->option('seller')) : $radar->sellerIds();
        foreach ($sellers as $sellerId) {
            $started = microtime(true);
            try {
                $r = $radar->compute($sellerId, $bench, !$this->option('no-notify'), $today);
                $this->line(sprintf('Seller %d: score %s, %d card(s), %d new — %d ms', $sellerId,
                    $r['score'] ?? '—', $r['cards'], $r['new'], (microtime(true) - $started) * 1000));
            } catch (\Throwable $e) {
                Log::error("[GrowthRadar] Seller $sellerId failed: " . $e->getMessage());
                $this->error("Seller $sellerId: " . $e->getMessage());
            }
        }
        $this->info(count($sellers) . ' seller(s) computed');
        return self::SUCCESS;
    }
}
