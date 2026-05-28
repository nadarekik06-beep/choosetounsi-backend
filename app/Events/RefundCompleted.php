<?php

namespace App\Events;

use App\Models\RefundDeliveryTask;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: RefundCompleted
 *
 * Fired when a delivery guy marks a RefundDeliveryTask as COMPLETED.
 *
 * The MarkOrderRefunded listener subscribes to this event and:
 *   1. Updates orders.status         → 'refunded'
 *   2. Updates seller_orders.status  → 'refunded'
 *   3. Updates complaint.refund_status → 'completed'
 *
 * This event is dispatched from:
 *   RefundDeliveryController::updateGuyStatus()
 *   when newStatus === 'completed'
 */
class RefundCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * The completed refund task.
     * Passed as a fresh() instance so all relationships are accessible.
     */
    public RefundDeliveryTask $task;

    public function __construct(RefundDeliveryTask $task)
    {
        $this->task = $task;
    }
}