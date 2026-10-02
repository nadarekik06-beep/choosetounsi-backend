<?php

namespace App\Jobs;

use App\Services\Forecast\ForecastAlerts;
use App\Services\Forecast\ForecastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Recompute one seller's forecasts (nightly run, or after a confirmed order /
 * restock). Unique per seller while queued, so a burst of orders is one run.
 */
class ComputeSellerForecast implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 2;
    public $timeout = 300;
    public $uniqueFor = 600;

    public function __construct(public int $sellerId, public bool $withAlerts = true)
    {
        $this->onQueue(config('forecast.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->sellerId;
    }

    public function handle(ForecastService $service, ForecastAlerts $alerts): void
    {
        $computed = $service->computeSeller($this->sellerId);
        if ($this->withAlerts) {
            $alerts->process($this->sellerId, $computed, $service->settings($this->sellerId), $service->today());
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Forecast] Seller {$this->sellerId} failed: {$e->getMessage()}");
    }
}
