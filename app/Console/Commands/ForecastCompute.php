<?php

namespace App\Console\Commands;

use App\Jobs\ComputeSellerForecast;
use App\Services\Forecast\EventEffectMeasurer;
use App\Services\Forecast\ForecastService;
use Illuminate\Console\Command;

/**
 * Nightly forecast run: measure finished calendar events, shrink old snapshot
 * payloads, then recompute every eligible seller (queued, or --sync).
 */
class ForecastCompute extends Command
{
    protected $signature = 'forecast:compute
        {--seller=* : Only these seller ids}
        {--sync : Compute in this process instead of queueing}
        {--no-alerts : Do not send alerts}';

    protected $description = 'Recompute sales forecasts (nightly)';

    public function handle(ForecastService $service, EventEffectMeasurer $measurer): int
    {
        $today = $service->today();

        foreach ($measurer->measurable($today) as $event) {
            $n = $measurer->measure($event);
            $this->line("Measured {$event->key} {$event->starts_on->toDateString()}: {$n} categories");
        }
        $compacted = ForecastService::compactOldSnapshots($today);
        if ($compacted) $this->line("Compacted {$compacted} old snapshots");

        $sellers = $this->option('seller') ? array_map('intval', $this->option('seller')) : $service->eligibleSellerIds();
        foreach ($sellers as $sellerId) {
            $job = new ComputeSellerForecast($sellerId, !$this->option('no-alerts'));
            if ($this->option('sync')) {
                $started = microtime(true);
                app()->call([$job, 'handle']);
                $this->line(sprintf('Seller %d done in %d ms', $sellerId, (microtime(true) - $started) * 1000));
            } else {
                dispatch($job);
            }
        }
        $this->info(count($sellers) . ' seller(s) ' . ($this->option('sync') ? 'computed' : 'queued'));
        return self::SUCCESS;
    }
}
