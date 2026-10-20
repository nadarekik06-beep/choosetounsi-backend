<?php

namespace App\Jobs\Intigo;

use App\Services\Intigo\IntigoStatusSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Applies one stored Intigo status event (App\Services\Intigo\IntigoStatusSync).
 * The webhook queues it on the "default" queue (the worker listens to
 * notifications,emails,default) and answers 200 at once; an event left
 * unprocessed (crash, lock) is picked up again by intigo:sync-statuses.
 */
class ProcessIntigoEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'default';

    public $tries = 3;
    public $timeout = 60;

    public function __construct(public int $eventId) {}

    public function handle(IntigoStatusSync $sync): void
    {
        try {
            $sync->process($this->eventId);
        } catch (\Throwable $e) {
            // Logged by process(); the event stays unprocessed for the safety net
            Log::warning("[Intigo] event {$this->eventId} left for intigo:sync-statuses: " . $e->getMessage());
        }
    }
}
