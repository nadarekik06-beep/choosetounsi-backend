<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\RefundDeliveryTask;
use App\Notifications\Buyer\RefundNotification;

/**
 * The buyer's return is done: refund (or exchange) completed after the courier
 * brought the item back. Send through App\Services\Notifications\BuyerNotifier.
 */
class RefundCompletedNotification extends RefundNotification
{
    public function __construct(RefundDeliveryTask $task, Order $order)
    {
        parent::__construct($task, $order, 'completed');
    }
}
