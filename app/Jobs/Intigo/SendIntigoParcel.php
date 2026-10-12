<?php

namespace App\Jobs\Intigo;

use App\Models\SellerOrder;
use App\Services\Intigo\ParcelSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Creates a seller_order's Intigo parcel (App\Services\Intigo\ParcelSender),
 * off the seller's "prepared" request. Only ambiguous failures (timeout, 5xx,
 * no answer) come back: after config('intigo.send.backoff') and at most
 * config('intigo.send.max_attempts') create attempts in total (counted on the
 * parcel, intigo_attempts). Every re-try starts with a cid lookup.
 */
class SendIntigoParcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Releases count as tries; the real limit is intigo_attempts. */
    public $tries = 12;

    public $timeout = 90;

    public function __construct(public int $sellerOrderId)
    {
        $this->afterCommit();
    }

    /**
     * From a web request: queued normally, or run after the response is sent
     * when the queue is "sync", so the seller's click never waits for Intigo.
     * Never throws.
     */
    public static function dispatchSafely(int $sellerOrderId): void
    {
        try {
            if (config('queue.default') === 'sync' && !app()->runningInConsole()) {
                self::dispatchAfterResponse($sellerOrderId);
            } else {
                self::dispatch($sellerOrderId);
            }
        } catch (\Throwable $e) {
            // The parcel stays 'pending'; intigo:sync-statuses picks it up again
            Log::error("[Intigo] could not queue the send of seller_order {$sellerOrderId}: " . $e->getMessage());
        }
    }

    public function handle(ParcelSender $sender): void
    {
        $result = $sender->send($this->sellerOrderId);

        if ($result['result'] === 'busy') {
            $this->release(60);
            return;
        }
        if ($result['result'] !== 'retry') {
            return;
        }

        $attempts = (int) SellerOrder::whereKey($this->sellerOrderId)->value('intigo_attempts');
        $max      = (int) config('intigo.send.max_attempts', 4);
        if ($attempts >= $max) {
            $sender->giveUp($this->sellerOrderId, "Gave up after {$attempts} attempts: {$result['message']}");
            return;
        }
        $backoff = config('intigo.send.backoff', [60, 300, 900]);
        $this->release($backoff[min($attempts - 1, count($backoff) - 1)] ?? 300);
    }

    public function failed(\Throwable $e): void
    {
        app(ParcelSender::class)->giveUp($this->sellerOrderId, 'Send job failed: ' . mb_substr($e->getMessage(), 0, 200));
    }
}
