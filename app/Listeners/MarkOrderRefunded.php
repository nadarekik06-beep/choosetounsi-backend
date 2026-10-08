<?php

namespace App\Listeners;

use App\Events\RefundCompleted;
use App\Models\Complaint;
use App\Services\Returns\RefundTaskSync;
use App\Services\Returns\ReturnService;
use Illuminate\Support\Facades\Log;

/**
 * Triggered by: RefundCompleted — the courier marked the return pick-up task
 * 'completed', i.e. the parcel was dropped at the seller's.
 *
 * Nothing is refunded here any more (COD rule: money only after the item is
 * back AND inspected). The seller / admin is asked to confirm the reception
 * and the condition of each item (ReturnService::receive), then the admin
 * issues the refund (ReturnService::refund), which reverses stock and finance.
 *
 * Legacy exchange tasks just close their complaint.
 */
class MarkOrderRefunded
{
    public function handle(RefundCompleted $event): void
    {
        $task = $event->task;

        try {
            RefundTaskSync::mirror($task);
            $complaint = Complaint::find($task->complaint_id);
            if (!$complaint) {
                Log::error("[RefundCompleted] Task #{$task->id} has no complaint.");
                return;
            }

            $service = app(ReturnService::class);

            if ($complaint->isExchange()) {
                if ($complaint->canTransitionTo(Complaint::STATUS_CLOSED)) {
                    $complaint->update(['status' => Complaint::STATUS_CLOSED, 'resolved_at' => now()]);
                    $service->event($complaint, Complaint::STATUS_CLOSED, $task->deliveryGuy, 'delivery', 'Legacy exchange delivered');
                }
                return;
            }

            $service->deliveredToSeller($complaint, $task->deliveryGuy);
        } catch (\Throwable $e) {
            Log::error("[RefundCompleted] Failed for task #{$task->id}: " . $e->getMessage());
        }
    }
}
