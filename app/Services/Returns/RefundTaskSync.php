<?php

namespace App\Services\Returns;

use App\Models\Complaint;
use App\Models\RefundDeliveryTask;

/**
 * Keeps the delivery app's pick-up task (refund_delivery_tasks) and the
 * return's status in step. The task mirrors the courier's side only:
 *   assigned → return pickup_scheduled, picked_up → picked_up,
 *   completed (parcel dropped at the shop) → the seller must inspect it.
 */
class RefundTaskSync
{
    /** A cancelled return: a task no courier has collected yet disappears. */
    public static function cancel(Complaint $complaint): void
    {
        RefundDeliveryTask::where('complaint_id', $complaint->id)
            ->whereIn('status', [RefundDeliveryTask::STATUS_PENDING, RefundDeliveryTask::STATUS_ASSIGNED])
            ->delete();
        $complaint->update(['refund_task_id' => null, 'refund_status' => null]);
    }

    public static function mirror(RefundDeliveryTask $task): void
    {
        Complaint::where('id', $task->complaint_id)->update(['refund_status' => $task->status]);
    }
}
