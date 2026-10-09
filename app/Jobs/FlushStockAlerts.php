<?php

namespace App\Jobs;

use App\Services\StockAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends a seller's pending stock crossings as grouped notifications
 * (StockAlertService::flush). Dispatched with the grouping window as delay.
 */
class FlushStockAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;

    public function __construct(public int $sellerId) {}

    public function handle(StockAlertService $alerts): void
    {
        $alerts->flush($this->sellerId);
    }
}
