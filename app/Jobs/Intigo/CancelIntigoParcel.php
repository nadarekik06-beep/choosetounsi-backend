<?php

namespace App\Jobs\Intigo;

use App\Services\Intigo\IntigoAlerts;
use App\Services\Intigo\ParcelCanceller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * An order cancelled while its parcel is at Intigo: cancel it there during the
 * pickup phase, flag it to the admin otherwise (App\Services\Intigo\ParcelCanceller).
 */
class CancelIntigoParcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 4;

    public function __construct(public int $sellerOrderId, public string $why = 'Order cancelled')
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ParcelCanceller $canceller): void
    {
        $result = $canceller->cancel($this->sellerOrderId, $this->why);
        if ($result['result'] === 'retry') {
            if ($this->attempts() >= $this->tries) {
                app(IntigoAlerts::class)->flag($this->sellerOrderId, 'Intigo cancellation failed', "{$this->why}: Intigo didn't answer ({$result['message']}). Cancel the parcel in the Intigo dashboard.", 'cancel_failed');
                return;
            }
            $this->release($this->backoff()[$this->attempts() - 1] ?? 900);
        }
    }
}
